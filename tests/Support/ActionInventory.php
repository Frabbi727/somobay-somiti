<?php

declare(strict_types=1);

namespace Tests\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ManageRecords;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Finder\Finder;

/**
 * Collects every action the staff panel defines — page header and form actions, table record,
 * toolbar, header and empty-state actions, and the shared *Actions factories — without needing
 * records: actions are built, never evaluated.
 */
final class ActionInventory
{
    /**
     * @return array<string, Action> keyed "where › name"
     */
    public static function all(): array
    {
        $actions = [];
        $panel = Filament::getPanel('admin');

        $pages = $panel->getPages();

        foreach ($panel->getResources() as $resource) {
            foreach ($resource::getPages() as $registration) {
                $pages[] = $registration->getPage();
            }
        }

        foreach (array_unique($pages) as $pageClass) {
            $page = new $pageClass;
            $where = class_basename($pageClass);

            self::add($actions, $where, self::call($page, 'getHeaderActions'));

            if ($page instanceof CreateRecord || $page instanceof EditRecord) {
                self::add($actions, $where.' form', self::call($page, 'getFormActions'));
            }

            $table = self::table($page);

            if ($table !== null) {
                self::add($actions, $where.' table', [
                    ...$table->getRecordActions(),
                    ...$table->getToolbarActions(),
                    ...$table->getHeaderActions(),
                    ...$table->getEmptyStateActions(),
                ]);
            }
        }

        foreach (self::factories() as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC) as $method) {
                if ($method->getNumberOfRequiredParameters() === 0 && $method->getDeclaringClass()->getName() === $class) {
                    self::add($actions, class_basename($class), [$method->invoke(null)]);
                }
            }
        }

        return $actions;
    }

    /**
     * @param  array<string, Action>  $actions
     * @param  array<mixed>  $found
     */
    private static function add(array &$actions, string $where, array $found): void
    {
        foreach ($found as $action) {
            if ($action instanceof ActionGroup) {
                self::add($actions, $where, $action->getActions());
            } elseif ($action instanceof Action) {
                $actions[$where.' › '.$action->getName()] = $action;
            }
        }
    }

    /**
     * @return array<mixed>
     */
    private static function call(object $object, string $method): array
    {
        if (! method_exists($object, $method)) {
            return [];
        }

        $reflection = new ReflectionMethod($object, $method);

        return (array) $reflection->invoke($object);
    }

    private static function table(Page $page): ?Table
    {
        if (! $page instanceof HasTable) {
            return null;
        }

        $table = Table::make($page);

        if ($page instanceof ListRecords || $page instanceof ManageRecords) {
            return $page::getResource()::table($table);
        }

        return method_exists($page, 'table') ? $page->table($table) : null;
    }

    /**
     * @return list<class-string>
     */
    private static function factories(): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in(app_path('Filament'))->path('Actions')->name('*.php') as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $classes[] = 'App\\Filament\\'.$relative;
        }

        return $classes;
    }

    public static function property(Action $action, string $name): mixed
    {
        return (new ReflectionProperty($action, $name))->getValue($action);
    }
}
