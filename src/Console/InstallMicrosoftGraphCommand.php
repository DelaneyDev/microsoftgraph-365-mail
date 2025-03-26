<?php
namespace LLoadout\Microsoftgraph\Console;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
class InstallMicrosoftGraphCommand extends Command
{
   protected $signature = 'microsoftgraph:install';
   protected $description = 'Install Microsoft Graph 365 support (config, model, migration)';
   public function handle()
   {
       $this->info('Installing Microsoft Graph 365...');
       // Config
       $configSource = __DIR__ . '/../../config/microsoftgraph.php';
       $configDest = config_path('microsoftgraph.php');
       if (!File::exists($configDest)) {
           File::copy($configSource, $configDest);
           $this->info('✓ Config published to config/microsoftgraph.php');
       } else {
           $this->comment('Config already exists, skipping...');
       }
       // Model
       $modelSource = __DIR__ . '/../../Models/MicrosoftGraphAccessToken.php';
       $modelDest = app_path('Models/MicrosoftGraphAccessToken.php');
       if (!File::exists($modelDest)) {
           File::ensureDirectoryExists(app_path('Models'));
           File::copy($modelSource, $modelDest);
           $this->info('✓ Model published to app/Models/MicrosoftGraphAccessToken.php');
       } else {
           $this->comment('Model already exists, skipping...');
       }
       // Migration
       $timestamp = date('Y_m_d_His');
       $migrationSource = __DIR__ . '/../../Database/Migrations/create_microsoft_graph_access_tokens_table.php.stub';
       $migrationDest = database_path("migrations/{$timestamp}_create_microsoft_graph_access_tokens_table.php");
       if (!File::exists($migrationDest)) {
           File::copy($migrationSource, $migrationDest);
           $this->info("✓ Migration published to database/migrations");
       } else {
           $this->comment("Migration already exists, skipping...");
       }
       if ($this->confirm('Run migrations now?')) {
           $this->call('migrate');
       }
       $this->info('✓ Microsoft Graph 365 setup complete!');
   }
}