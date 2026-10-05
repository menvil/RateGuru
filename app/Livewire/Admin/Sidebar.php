<?php

namespace App\Livewire\Admin;

use App\Enums\UserRole;
use App\Filament\Support\AdminShellNavigation;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Admin v2 sidebar, registered with the panel's sidebarLivewireComponent().
 *
 * One markup serves every width: the 300px sidebar from 1280px up, the 68px
 * icon rail between 1024px and 1280px, and the same sidebar as an overlay
 * drawer when the rail is expanded or below 1024px. What it lists comes from
 * Filament's navigation through AdminShellNavigation, so a destination the
 * user may not open is never drawn. It runs no queries: the account block
 * reads the user already authenticated for this request.
 */
final class Sidebar extends Component
{
    /** Filament dispatches this when navigation, such as a badge, changes. */
    #[On('refresh-sidebar')]
    public function refresh(): void {}

    public function render(): View
    {
        return view('livewire.admin.sidebar', [
            'sections' => AdminShellNavigation::sections(),
            'account' => $this->account(),
            'logoutUrl' => filament()->getLogoutUrl(),
        ]);
    }

    /**
     * @return array{name: string, email: string, role: ?string, initials: string}|null
     */
    private function account(): ?array
    {
        $user = filament()->auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $name = filament()->getUserName($user);

        return [
            'name' => $name,
            'email' => (string) $user->email,
            'role' => match ($user->role) {
                UserRole::Admin => 'Administrator',
                UserRole::Moderator => 'Moderator',
                default => null,
            },
            'initials' => Str::of($name)
                ->explode(' ')
                ->filter()
                ->take(2)
                ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
                ->implode(''),
        ];
    }
}
