<?php

namespace ProofAge\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use ProofAge\Laravel\Middleware\VerifyWebhookSignature;
use ProofAge\Laravel\ProofAgeClient;
use ProofAge\Laravel\Resources\WorkspaceResource;

class VerifySetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'proofage:verify-setup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify that ProofAge configuration is set and test workspace connection';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->checkConfiguration()) {
            return self::FAILURE;
        }

        $result = $this->checkWorkspaceAndWebhook();

        if ($result === null) {
            return self::FAILURE;
        }

        if ($result['webhook_configured']) {
            $this->info('✅ ProofAge setup verified successfully!');
        } else {
            $this->warn('⚠️  ProofAge setup partially verified. Webhooks are not configured.');
        }

        return self::SUCCESS;
    }

    private function checkConfiguration(): bool
    {
        $requiredConfig = [
            'api_key' => config('proofage.api_key'),
            'secret_key' => config('proofage.secret_key'),
            'base_url' => config('proofage.base_url'),
        ];

        $missing = [];
        foreach ($requiredConfig as $key => $value) {
            if (empty($value)) {
                $missing[] = $key;
            }
        }

        if (! empty($missing)) {
            $this->error('Missing configuration settings: '.implode(', ', $missing));
            $this->error('Please publish and configure the config/proofage.php file');

            return false;
        }

        $this->info('✅ Configuration is valid');

        return true;
    }

    private function checkWorkspaceAndWebhook(): ?array
    {
        try {
            $client = app(ProofAgeClient::class);
            $workspace = new WorkspaceResource($client);

            $data = $workspace->get();

            if ($data === null) {
                $this->error('Failed to retrieve workspace data');

                return null;
            }

            $this->info('✅ Workspace connection successful');

            $webhookConfigured = false;

            if (isset($data['webhook_url']) && ! empty($data['webhook_url'])) {
                $webhookUrl = $data['webhook_url'];
                $this->info('✅ Webhook URL is configured '.$webhookUrl);

                $routeCheck = $this->checkWebhookRouteExists($webhookUrl);

                if ($routeCheck['route_found'] && $routeCheck['post_method_found']) {
                    $this->info('✅ Webhook route found: '.implode('|', $routeCheck['route_info']->methods()).' '.$routeCheck['route_info']->uri().' -> '.$routeCheck['route_info']->getActionName());

                    if ($routeCheck['middleware_found']) {
                        $this->info('✅ Webhook route is protected with VerifyWebhookSignature middleware');
                        $webhookConfigured = true;
                    } else {
                        $this->error('❌ Webhook routes must be protected with the VerifyWebhookSignature middleware to prevent unauthorized access.');
                        $this->line('   Add the \'proofage.verify_webhook\' middleware to your route:');

                        $routeUri = $routeCheck['route_info']->uri();
                        $actionName = $routeCheck['route_info']->getActionName();

                        if (str_contains($actionName, '@')) {
                            [$controller, $method] = explode('@', $actionName);
                            $this->line('   Route::post(\''.$routeUri.'\', ['.$controller.'::class, \''.$method.'\'])->middleware(\'proofage.verify_webhook\');');
                        } else {
                            $this->line('   Route::post(\''.$routeUri.'\', \''.$actionName.'\')->middleware(\'proofage.verify_webhook\');');
                        }
                    }
                } elseif ($routeCheck['route_found'] && ! $routeCheck['post_method_found']) {
                    $this->error('❌ Webhook route found but does not accept the POST method. Current methods: '.implode('|', $routeCheck['route_info']->methods()));
                } else {
                    $this->error('❌ Webhook route not found in Laravel routes');
                    $this->line('   Make sure you have created a route and controller method to handle webhook requests.');

                    $webhookPath = $routeCheck['webhook_path'];
                    $this->line('   Example: Route::post(\'/'.$webhookPath.'\', [WebhookController::class, \'handle\'])->middleware(\'proofage.verify_webhook\');');
                }
            } else {
                $this->warn('⚠️  Webhook URL is not configured. Webhook notifications will not be sent.');
                $this->line('   You can configure webhook_url in your ProofAge workspace settings.');
            }

            return [
                'workspace_data' => $data,
                'webhook_configured' => $webhookConfigured,
            ];

        } catch (\Exception $e) {
            $this->error('Failed to retrieve workspace data. Please check your API key and secret key configuration.');
            $this->line('Error details: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Check if webhook route exists in Laravel application
     */
    private function checkWebhookRouteExists(string $webhookUrl): array
    {
        try {
            // Parse the path from webhook URL
            $parsedUrl = parse_url($webhookUrl);
            $webhookPath = $parsedUrl['path'] ?? '';

            // Remove leading slash if present
            $webhookPath = ltrim($webhookPath, '/');

            // Get all registered routes
            $routes = Route::getRoutes();
            $routeFound = false;
            $postMethodFound = false;
            $middlewareFound = false;
            $routeInfo = null;

            foreach ($routes as $route) {
                $routeUri = $route->uri();

                // Check if route URI matches webhook path
                if ($routeUri === $webhookPath || $routeUri === '/'.$webhookPath) {
                    $routeFound = true;
                    $routeInfo = $route;

                    // Check if route accepts POST method
                    $methods = $route->methods();
                    if (in_array('POST', $methods) || in_array('ANY', $methods)) {
                        $postMethodFound = true;
                    }

                    // Check if route has VerifyWebhookSignature middleware
                    $middleware = $route->middleware();
                    foreach ($middleware as $middlewareItem) {
                        // Check for both the alias and the full class name
                        if ($middlewareItem === 'proofage.verify_webhook' ||
                            $middlewareItem === VerifyWebhookSignature::class) {
                            $middlewareFound = true;
                            break;
                        }
                    }

                    break;
                }
            }

            return [
                'route_found' => $routeFound,
                'post_method_found' => $postMethodFound,
                'middleware_found' => $middlewareFound,
                'route_info' => $routeInfo,
                'webhook_path' => $webhookPath,
            ];

        } catch (\Exception $e) {
            return [
                'route_found' => false,
                'post_method_found' => false,
                'middleware_found' => false,
                'route_info' => null,
                'webhook_path' => '',
                'error' => $e->getMessage(),
            ];
        }
    }
}
