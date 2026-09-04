<?php

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Filament\Admin\Resources\Concerns\FillsCustomFields;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use App\Services\TenantStatementPdfService;
use App\Support\Filament\PdfDownloadAction;
use App\Support\Filament\RefreshesRecordState;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EditTenant extends EditRecord
{
    use FillsCustomFields;
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
            Action::make('mobileAppAccess')
                ->label(fn () => $this->record->password
                    ? __('admin.tenants.reset_mobile')
                    : __('admin.tenants.setup_mobile'))
                ->icon('heroicon-o-key')
                ->color('primary')
                ->visible(fn () => Auth::user()?->hasAnyRole(['super_admin', 'manager']) ?? false)
                ->authorize(fn () => Auth::user()?->hasAnyRole(['super_admin', 'manager']) ?? false)
                ->modalHeading(fn () => __('admin.tenants.mobile_modal_heading', ['name' => $this->record->name]))
                ->modalDescription(__('admin.tenants.mobile_modal_description'))
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
                        ->helperText(__('admin.tenants.mobile_password_helper')),
                ])
                ->action(function (array $data) {
                    // action() is the real gate — mountAction() never checks visible(); a role with
                    // tenants.edit but not super_admin/manager must not set/reset portal credentials.
                    abort_unless(Auth::user()?->hasAnyRole(['super_admin', 'manager']) ?? false, 403);
                    // `tenants.password` is the MOBILE APP credential — LoginTenantAction checks it
                    // for `/api/v1`. The web portal authenticates a TenantUser (guard `portal` →
                    // provider `tenant_users`) and never reads this column, so this button cannot
                    // grant /portal access however it is labelled. Portal logins: Portal Users tab.
                    $this->record->update([
                        'password' => Hash::make($data['password']),
                        'status' => 'active',
                    ]);
                    // Setting up the portal ACTIVATES the tenant — and `status` is on this form.
                    $this->refreshFormData(['status']);

                    Notification::make()
                        ->title(__('admin.tenants.mobile_set'))
                        ->body(__('admin.tenants.mobile_set_body', [
                            'email' => $this->record->email ?? '—',
                            'password' => $data['password'],
                        ]))
                        ->success()
                        ->persistent()
                        ->send();
                }),
            PdfDownloadAction::make('statement')
                ->label(__('admin.statement.action_label'))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->recipient(fn (Tenant $record) => $record)
                // Scoped, exactly as the two sibling call sites are (`TenantsTable`,
                // `ArCollections`), each with a comment saying why. Omitting it here meant a
                // property-restricted operator could download a shared tenant's WHOLE-PORTFOLIO
                // statement — every filter in `data()` is `->when($visibleAssetIds !== null, …)`,
                // so null is unrestricted, rollups included. A tenant leasing in two malls is
                // legitimately on either mall's register, so this needed no special access.
                // Secondary, and true even for super_admin: two identically-labelled buttons
                // produced DIFFERENT documents for the same tenant.
                ->document(fn (Tenant $record, string $locale): string => app(TenantStatementPdfService::class)
                    ->build($record, TenantScope::visibleAssetIds(), null, null, $locale))
                ->filename(fn (Tenant $record): string => app(TenantStatementPdfService::class)->filename($record))
                // Statement is tenant financial data — gate server-side (was ungated).
                ->authorize(fn () => Auth::user()?->can('tenants.view') ?? false),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
