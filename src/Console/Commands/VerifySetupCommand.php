<?php

namespace ProofAge\Laravel\Console\Commands;

use Illuminate\Console\Command;
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
        $this->info('🔍 Verifying ProofAge setup...');

        // Check configuration
        $this->checkConfiguration();

        // Check workspace connection
        $this->checkWorkspaceConnection();

        $this->info('✅ ProofAge setup verified successfully!');

        return self::SUCCESS;
    }

    /**
     * Check configuration
     */
    private function checkConfiguration(): void
    {
        $this->info('Checking configuration...');

        $requiredConfig = [
            'api_key' => config('proofage.api_key'),
            'secret_key' => config('proofage.secret_key'),
            'base_url' => config('proofage.base_url'),
        ];

        $missing = [];
        foreach ($requiredConfig as $key => $value) {
            if (empty($value)) {
                $missing[] = $key;
            } else {
                $this->line("✓ {$key}: configured");
            }
        }

        if (! empty($missing)) {
            $this->error('Missing configuration settings: '.implode(', ', $missing));
            $this->error('Please publish and configure the config/proofage.php file');
            exit(self::FAILURE);
        }

        $this->info('✅ Configuration is valid');
    }

    /**
     * Check workspace connection
     */
    private function checkWorkspaceConnection(): void
    {
        $this->info('Testing workspace connection...');

        try {
            $client = app(ProofAgeClient::class);
            $workspace = new WorkspaceResource($client);

            $data = $workspace->get();

            if ($data === null) {
                $this->error('Failed to retrieve workspace data');

                return;
            }

            $this->info('✅ Workspace connection successful');
            $this->line('Workspace data:');
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        } catch (\Exception $e) {
            $this->error('Failed to retrieve workspace data. Please check your API key and secret key configuration.');
            $this->line('Error details: '.$e->getMessage());
            exit(self::FAILURE);
        }
    }
}
