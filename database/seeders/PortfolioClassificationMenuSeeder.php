<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Menu;

class PortfolioClassificationMenuSeeder extends Seeder
{
    public function run(): void
    {
        // Remove the incorrectly placed sidebar menu entry if it exists
        $removed = Menu::where('route', 'reports.loans.portfolio_classification')
            ->delete();

        if ($removed) {
            $this->command->info("Removed {$removed} sidebar menu entry/entries for Portfolio Classification.");
        } else {
            $this->command->info('No sidebar menu entry found to remove.');
        }

        $this->command->info('Portfolio Classification Report is accessible from the Loans Reports hub page at /reports/loans.');
    }
}
