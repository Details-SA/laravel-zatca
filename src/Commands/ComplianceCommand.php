<?php

namespace Corecave\Zatca\Commands;

use Corecave\Zatca\Certificate\Certificate;
use Corecave\Zatca\Services\OnboardingService;
use Illuminate\Console\Command;

class ComplianceCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'zatca:compliance
                            {--otp= : OTP from FATOORA portal}
                            {--csr= : Path to CSR file}
                            {--private-key= : Path to private key file}
                            {--skip-checks : Skip compliance invoice checks}';

    /**
     * The console command description.
     */
    protected $description = 'Complete ZATCA compliance onboarding process';

    /**
     * Execute the console command.
     */
    public function handle(OnboardingService $onboarding): int
    {
        $this->info('Starting ZATCA Compliance Process...');
        $this->newLine();

        // Get OTP
        $otp = $this->option('otp');
        if (empty($otp)) {
            $otp = $this->ask('Enter OTP from FATOORA portal');
        }

        // Get or generate CSR
        $csrData = $this->getCsrData();

        if (!$csrData) {
            return self::FAILURE;
        }

        // Step 1: Request Compliance CSID
        $this->info('Step 1: Requesting Compliance CSID...');

        try {
            $complianceCert = $onboarding->getComplianceCsid($csrData['csr'], $csrData['private_key'], $otp);

            $this->info('Compliance CSID received and stored!');
            $this->components->twoColumnDetail('Request ID', $complianceCert->getRequestId() ?? 'N/A');
            $this->newLine();
        }
        catch (\Exception $e) {
            $this->error('Failed to get Compliance CSID: ' . $e->getMessage());

            return self::FAILURE;
        }

        // Step 2: Run compliance checks
        if (!$this->option('skip-checks')) {
            $this->info('Step 2: Running compliance checks...');

            $invoiceTypes = config('zatca.csr.invoice_types', '1100');
            $checksResult = $onboarding->runComplianceChecks($complianceCert, $invoiceTypes);

            foreach ($checksResult['results'] as $result) {
                $this->line("  Submitting {$result['sample']['type']} {$result['sample']['subtype']}...");

                if ($result['status'] === 'PASS') {
                    $this->info('    PASS');
                }
                elseif ($result['accepted']) {
                    $this->warn("    PASS (with warnings)");
                    $this->displayValidationWarnings($result['response']);
                }
                elseif ($result['status'] === 'ERROR') {
                    $this->error("    ERROR: {$result['error']}");
                }
                else {
                    $this->error("    FAIL: {$result['status']}");
                    $this->displayValidationErrors($result['response']);
                }
            }

            $this->newLine();
            $this->components->twoColumnDetail('Passed', (string)$checksResult['passed_count']);
            $this->components->twoColumnDetail('Failed', (string)$checksResult['failed_count']);

            if (!$checksResult['passed']) {
                $this->error('Compliance checks failed.');

                return self::FAILURE;
            }
        }
        else {
            $this->warn('Skipping compliance checks (--skip-checks)');
        }

        $this->newLine();
        $this->info('Compliance process completed successfully!');
        $this->newLine();

        $this->warn('Next steps:');
        $this->line('  Run: php artisan zatca:production-csid');
        $this->line('  to request your Production CSID');

        return self::SUCCESS;
    }

    /**
     * Get CSR data from file or generate new.
     */
    protected function getCsrData(): ?array
    {
        $csrPath = $this->option('csr');
        $keyPath = $this->option('private-key');

        if ($csrPath && $keyPath) {
            if (!file_exists($csrPath) || !file_exists($keyPath)) {
                $this->error('CSR or private key file not found');

                return null;
            }

            return [
                'csr' => file_get_contents($csrPath),
                'private_key' => file_get_contents($keyPath),
            ];
        }

        // Check for stored CSR
        $storedCsr = storage_path('zatca/csr.pem');
        $storedKey = storage_path('zatca/private_key.pem');

        if (file_exists($storedCsr) && file_exists($storedKey)) {
            if ($this->confirm('Use existing CSR from storage?', true)) {
                return [
                    'csr' => file_get_contents($storedCsr),
                    'private_key' => file_get_contents($storedKey),
                ];
            }
        }

        // Generate new CSR
        $this->warn('No CSR found. Generating new CSR...');
        $this->call('zatca:generate-csr', ['--save' => true]);

        if (file_exists($storedCsr) && file_exists($storedKey)) {
            return [
                'csr' => file_get_contents($storedCsr),
                'private_key' => file_get_contents($storedKey),
            ];
        }

        return null;
    }



    /**
     * Display validation errors from response.
     */
    protected function displayValidationErrors(array $response): void
    {
        $errors = $response['validationResults']['errorMessages'] ?? [];

        foreach ($errors as $error) {
            $this->line("      - {$error['message']} ({$error['code']})");
        }
    }

    /**
     * Display validation warnings from response.
     */
    protected function displayValidationWarnings(array $response): void
    {
        $warnings = $response['validationResults']['warningMessages'] ?? [];

        foreach ($warnings as $warning) {
            $this->line("      - {$warning['message']} ({$warning['code']})");
        }
    }
}