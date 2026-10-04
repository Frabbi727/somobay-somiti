<?php

declare(strict_types=1);

namespace App\Filament\Resources\StatementImports\Schemas;

use App\Domain\Accounting\Statements\StatementCsvParser;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class StatementImportForm
{
    /**
     * @var list<string>
     */
    public const array COLUMNS = ['date', 'description', 'reference', 'amount', 'credit', 'debit', 'balance'];

    public static function configure(Schema $schema): Schema
    {
        $headerOptions = fn (Get $get): array => self::headerOptions($get('file_path'));

        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('method')
                        ->label(__('statements.field.account'))
                        ->options(self::accounts())
                        ->default(PaymentMethod::Bank->value)
                        ->required()
                        ->native(false),
                    FileUpload::make('file_path')
                        ->label(__('statements.field.file'))
                        ->helperText(__('statements.field.file_help'))
                        ->disk('local')
                        ->directory('statements')
                        ->visibility('private')
                        ->storeFileNamesIn('filename')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'])
                        ->maxSize(5120)
                        ->required()
                        ->live(),
                ]),
            Section::make(__('statements.field.columns'))
                ->columns(3)
                ->columnSpanFull()
                ->collapsed()
                ->schema(array_map(
                    fn (string $column): Select => Select::make('columns.'.$column)
                        ->label(__('statements.field.column_'.$column))
                        ->options($headerOptions)
                        ->native(false),
                    self::COLUMNS,
                )),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function accounts(): array
    {
        $options = [];

        foreach ([PaymentMethod::Bank, PaymentMethod::Bkash, PaymentMethod::Nagad] as $method) {
            $options[$method->value] = $method->getLabel();
        }

        return $options;
    }

    /**
     * Column headings of the file being uploaded, for the manual column choice.
     *
     * @return array<string, string>
     */
    public static function headerOptions(mixed $state): array
    {
        $file = is_array($state) ? reset($state) : $state;

        if (! $file instanceof TemporaryUploadedFile) {
            return [];
        }

        try {
            $headers = (new StatementCsvParser)->read((string) $file->get())['headers'];
        } catch (DomainRuleViolation) {
            return [];
        }

        return array_combine($headers, $headers);
    }
}
