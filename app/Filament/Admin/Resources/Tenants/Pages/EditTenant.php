<?php

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Services\TenantStatementPdfService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('portalAccess')
                ->label(fn () => $this->record->password
                    ? __('admin.tenants.reset_portal')
                    : __('admin.tenants.setup_portal'))
                ->icon('heroicon-o-key')
                ->color('primary')
                ->visible(fn () => Auth::user()?->hasAnyRole(['super_admin', 'manager']) ?? false)
                ->modalHeading(fn () => __('admin.tenants.portal_modal_heading', ['name' => $this->record->name]))
                ->modalDescription(__('admin.tenants.portal_modal_description'))
                ->modalSubmitActionLabel(__('admin.tenants.save_password'))
                ->fillForm(fn () => [
                    'password' => Str::password(10, symbols: false),
                ])
                ->schema([
                    TextInput::make('password')
                        ->label(__('admin.users.password'))
                        ->password()
                        ->revealable()
                        ->required()
                        ->minLength(6)
                        ->helperText(__('admin.tenants.portal_password_helper')),
                ])
                ->action(function (array $data) {
                    $this->record->update([
                        'password' => Hash::make($data['password']),
                        'status' => 'active',
                    ]);

                    Notification::make()
                        ->title(__('admin.tenants.portal_set'))
                        ->body(__('admin.tenants.portal_set_body', [
                            'email' => $this->record->email ?? '—',
                            'password' => $data['password'],
                        ]))
                        ->success()
                        ->persistent()
                        ->send();
                }),
            Action::make('statement')
                ->label(__('admin.statement.action_label'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $svc = app(TenantStatementPdfService::class);
                    $pdf = $svc->build($this->record);
                    return response()->streamDownload(
                        fn () => print($pdf),
                        $svc->filename($this->record),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
