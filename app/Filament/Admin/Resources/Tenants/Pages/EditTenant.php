<?php

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Services\TenantStatementPdfService;
use App\Support\Filament\RefreshesRecordState;
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
    use RefreshesRecordState;

    /**
     * Setting up portal access activates the tenant — from this page's own header action, on a
     * field this form renders.
     *
     * @return array<int, string>
     */
    protected function derivedStatePaths(): array
    {
        return ['status'];
    }

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
                ->authorize(fn () => Auth::user()?->hasAnyRole(['super_admin', 'manager']) ?? false)
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
                    // action() is the real gate — mountAction() never checks visible(); a role with
                    // tenants.edit but not super_admin/manager must not set/reset portal credentials.
                    abort_unless(Auth::user()?->hasAnyRole(['super_admin', 'manager']) ?? false, 403);
                    $this->record->update([
                        'password' => Hash::make($data['password']),
                        'status' => 'active',
                    ]);
                    // Setting up the portal ACTIVATES the tenant — and `status` is on this form.
                    $this->refreshFormData(['status']);

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
                // Statement is tenant financial data — gate server-side (was ungated).
                ->authorize(fn () => Auth::user()?->can('tenants.view') ?? false)
                ->action(function () {
                    $svc = app(TenantStatementPdfService::class);
                    $pdf = $svc->build($this->record);

                    return response()->streamDownload(
                        fn () => print ($pdf),
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
