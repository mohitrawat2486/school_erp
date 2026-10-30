<?php

namespace App\Providers;

use App\Models\User;
use App\Support\CurrentSchool;
use App\Support\TablePagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentSchool::class);
        $this->app->scoped(TablePagination::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Builder::macro('paginateTable', function (int $perPage = 20, array $columns = ['*'], string $pageName = 'page', ?array $fields = null) {
            return app(TablePagination::class)->paginate($this, $perPage, $columns, $pageName, $fields);
        });
        if (app()->environment('local') && app()->runningInConsole()) {
            DevCommands::register('php -d memory_limit=512M artisan queue:work result-pdfs --queue=result-pdfs --tries=1 --timeout=300 --memory=512', 'PDF Worker');
        }
        foreach (config('permissions') as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }
}
