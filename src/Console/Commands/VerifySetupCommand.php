<?php

namespace ProofAge\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use ProofAge\Laravel\Middleware\VerifyWebhookSignature;
use ProofAge\Laravel\ProofAgeClientFactory;
use ProofAge\Laravel\Resources\WorkspaceResource;
use ProofAge\Laravel\Support\ConfigResolver;

class VerifySetupCommand extends Command
{
    protected $signature = 'proofage:verify-setup
        {--config=proofage : Config prefix to read api_key/secret_key from}';

    protected $description = 'Verify that ProofAge configuration is set and test workspace connection';

    public function handle(): int
    {
        $configPrefix = $this->option('config');

        if (! $this->checkConfiguration($configPrefix)) {
            return self::FAILURE;
        }

        $result = $this->checkWorkspaceAndWebhook($configPrefix);

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

    private function checkConfiguration(string $configPrefix): bool
    {
        $config = ConfigResolver::resolve($configPrefix);

        $required = ['api_key', 'secret_key', 'base_url'];
        $missing = [];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                $missing[] = $key;
            }
        }

        if (! empty($missing)) {
            $this->error('Missing configuration settings: '.implode(', ', $missing));

            if ($configPrefix === 'proofage') {
                $this->error('Please publish and configure the config/proofage.php file');
            } else {
                $this->error("Please ensure '{$configPrefix}.api_key' and '{$configPrefix}.secret_key' are configured");
            }

            return false;
        }

        $this->info('✅ Configuration is valid');

        return true;
    }

    private function checkWorkspaceAndWebhook(string $configPrefix): ?array
    {
        try {
            $client = app(ProofAgeClientFactory::class)->make($configPrefix);
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

                $routeCheck = $this->checkWebhookRouteExists($webhookUrl, $configPrefix);

                if ($routeCheck['route_found'] && $routeCheck['post_method_found']) {
                    $this->info('✅ Webhook route found: '.implode('|', $routeCheck['route_info']->methods()).' '.$routeCheck['route_info']->uri().' -> '.$routeCheck['route_info']->getActionName());

                    if ($routeCheck['middleware_found']) {
                        if ($routeCheck['config_prefix_match']) {
                            $this->info('✅ Webhook route is protected with VerifyWebhookSignature middleware');
                            $webhookConfigured = true;
                        } else {
                            $this->warn('⚠️  Webhook route has VerifyWebhookSignature middleware but with a different config prefix.');
                            $this->warn("   Expected: '{$configPrefix}', found: '{$routeCheck['middleware_config_prefix']}'");
                            $this->warn('   The webhook will verify signatures with wrong keys.');
                        }
                    } else {
                        $this->error('❌ Webhook routes must be protected with the VerifyWebhookSignature middleware to prevent unauthorized access.');

                        $routeUri = $routeCheck['route_info']->uri();
                        $actionName = $routeCheck['route_info']->getActionName();
                        $middlewareHint = $configPrefix === 'proofage'
                            ? "'proofage.verify_webhook'"
                            : "'proofage.verify_webhook:{$configPrefix}'";

                        if (str_contains($actionName, '@')) {
                            [$controller, $method] = explode('@', $actionName);
                            $this->line("   Route::post('{$routeUri}', [{$controller}::class, '{$method}'])->middleware({$middlewareHint});");
                        } else {
                            $this->line("   Route::post('{$routeUri}', '{$actionName}')->middleware({$middlewareHint});");
                        }
                    }
                } elseif ($routeCheck['route_found'] && ! $routeCheck['post_method_found']) {
                    $this->error('❌ Webhook route found but does not accept the POST method. Current methods: '.implode('|', $routeCheck['route_info']->methods()));
                } else {
                    $this->error('❌ Webhook route not found in Laravel routes');
                    $this->line('   Make sure you have created a route and controller method to handle webhook requests.');

                    $webhookPath = $routeCheck['webhook_path'];
                    $middlewareHint = $configPrefix === 'proofage'
                        ? "'proofage.verify_webhook'"
                        : "'proofage.verify_webhook:{$configPrefix}'";
                    $this->line("   Example: Route::post('/{$webhookPath}', [WebhookController::class, 'handle'])->middleware({$middlewareHint});");
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

    private function checkWebhookRouteExists(string $webhookUrl, string $configPrefix): array
    {
        try {
            $parsedUrl = parse_url($webhookUrl);
            $webhookPath = ltrim($parsedUrl['path'] ?? '', '/');

            $routes = Route::getRoutes();
            $router = app(Router::class);
            $aliasMap = $router->getMiddleware();

            $routeFound = false;
            $postMethodFound = false;
            $middlewareFound = false;
            $configPrefixMatch = false;
            $middlewareConfigPrefix = null;
            $routeInfo = null;

            foreach ($routes as $route) {
                $routeUri = $route->uri();

                if ($routeUri === $webhookPath || $routeUri === '/'.$webhookPath) {
                    $routeFound = true;
                    $routeInfo = $route;

                    $methods = $route->methods();
                    if (in_array('POST', $methods) || in_array('ANY', $methods)) {
                        $postMethodFound = true;
                    }

                    foreach ($route->middleware() as $middlewareItem) {
                        $parts = explode(':', $middlewareItem, 2);
                        $resolved = $aliasMap[$parts[0]] ?? $parts[0];

                        if ($resolved === VerifyWebhookSignature::class) {
                            $middlewareFound = true;
                            $middlewareConfigPrefix = $parts[1] ?? 'proofage';
                            $configPrefixMatch = ($middlewareConfigPrefix === $configPrefix);
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
                'config_prefix_match' => $configPrefixMatch,
                'middleware_config_prefix' => $middlewareConfigPrefix,
                'route_info' => $routeInfo,
                'webhook_path' => $webhookPath,
            ];

        } catch (\Exception $e) {
            return [
                'route_found' => false,
                'post_method_found' => false,
                'middleware_found' => false,
                'config_prefix_match' => false,
                'middleware_config_prefix' => null,
                'route_info' => null,
                'webhook_path' => '',
                'error' => $e->getMessage(),
            ];
        }
    }
}
