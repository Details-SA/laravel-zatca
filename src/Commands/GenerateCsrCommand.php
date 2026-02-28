<?php

namespace Corecave\Zatca\Commands;

use Corecave\Zatca\DTO\OnboardingProfile;
use Corecave\Zatca\Services\OnboardingService;
use Illuminate\Console\Command;

class GenerateCsrCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'zatca:generate-csr
                            {--organization= : Organization name}
                            {--organization-unit= : Organization unit}
                            {--common-name= : Common name}
                            {--vat-number= : VAT registration number (15 digits)}
                            {--invoice-types=1100 : Invoice types (1000=B2C, 0100=B2B, 1100=Both)}
                            {--save : Save CSR and private key to files}
                            {--output= : Output directory for files}';

    /**
     * The console command description.
     */
    protected $description = 'Generate a CSR (Certificate Signing Request) for ZATCA onboarding';

    /**
     * Execute the console command.
     */
    public function handle(OnboardingService $onboarding): int
    {
        $this->info('Generating CSR for ZATCA onboarding...');
        $this->newLine();

        // Gather configuration into DTO
        $profile = $this->gatherConfig();

        try {
            $result = $onboarding->generateCsr($profile);

            $this->info('CSR generated successfully!');
            $this->newLine();

            // Display CSR info
            $this->components->twoColumnDetail('Serial Number', $profile->serialNumber);
            $this->components->twoColumnDetail('Organization', $profile->organization);
            $this->components->twoColumnDetail('VAT Number', $profile->vatNumber);
            $this->components->twoColumnDetail('Invoice Types', $this->getInvoiceTypesLabel($profile->invoiceTypes));
            $this->newLine();

            if ($this->option('save')) {
                $this->saveFiles($result);
            }
            else {
                $this->displayCsr($result);
            }

            $this->newLine();
            $this->warn('Next steps:');
            $this->line('  1. Log in to the FATOORA portal and generate an OTP');
            $this->line('  2. Run: php artisan zatca:compliance --otp=<YOUR_OTP>');

            return self::SUCCESS;
        }
        catch (\Exception $e) {
            $this->error('Failed to generate CSR: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Gather configuration from options or interactively.
     */
    protected function gatherConfig(): OnboardingProfile
    {
        $config = config('zatca.csr', []);

        // Organization
        $organization = $this->option('organization') ?? $config['organization'] ?? null;
        if (empty($organization)) {
            $organization = $this->ask('Organization name');
        }

        // Organization unit
        $organizationUnit = $this->option('organization-unit') ?? $config['organization_unit'] ?? null;
        if (empty($organizationUnit)) {
            $organizationUnit = $this->ask('Organization unit (branch/department)', 'Main Branch');
        }

        // Common name
        $commonName = $this->option('common-name') ?? $config['common_name'] ?? null;
        if (empty($commonName)) {
            $commonName = $this->ask('Common name', $organization);
        }

        // VAT number
        $vatNumber = $this->option('vat-number') ?? $config['vat_number'] ?? null;
        if (empty($vatNumber)) {
            $vatNumber = $this->ask('VAT registration number (15 digits starting with 3)');
        }

        // Invoice types
        $invoiceTypes = $this->option('invoice-types') ?? $config['invoice_types'] ?? '1100';

        return new OnboardingProfile(
            $organization,
            $organizationUnit,
            $commonName,
            $vatNumber,
            $invoiceTypes,
            $config['location'] ?? [],
            $config['business_category'] ?? 'Technology',
            null,
            config('zatca.environment', 'sandbox')
            );
    }

    /**
     * Save CSR and private key to files.
     */
    protected function saveFiles(array $result): void
    {
        $outputDir = $this->option('output') ?? storage_path('zatca');

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $csrPath = "{$outputDir}/csr.pem";
        $keyPath = "{$outputDir}/private_key.pem";

        file_put_contents($csrPath, $result['csr']);
        file_put_contents($keyPath, $result['private_key']);
        chmod($keyPath, 0600);

        $this->info('Files saved:');
        $this->components->twoColumnDetail('CSR', $csrPath);
        $this->components->twoColumnDetail('Private Key', $keyPath);
    }

    /**
     * Display CSR in terminal.
     */
    protected function displayCsr(array $result): void
    {
        $this->info('CSR:');
        $this->line($result['csr']);
        $this->newLine();

        $this->warn('Private Key (keep this secure!):');
        $this->line($result['private_key']);
    }

    /**
     * Get human-readable label for invoice types.
     */
    protected function getInvoiceTypesLabel(string $types): string
    {
        return match ($types) {
                '1000' => 'B2C only (Simplified)',
                '0100' => 'B2B only (Standard)',
                '1100' => 'Both B2B and B2C',
                default => $types,
            };
    }
}