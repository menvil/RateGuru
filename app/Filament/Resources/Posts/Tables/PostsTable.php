<?php

namespace App\Filament\Resources\Posts\Tables;

use App\Actions\Moderation\ApprovePostAction;
use App\Actions\Moderation\HidePostAction;
use App\Actions\Moderation\RejectPostAction;
use App\Enums\PostStatus;
use App\Models\Post;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Image')
                    ->square()
                    ->defaultImageUrl(null),
                TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->sortable()
                    ->limit(60),
                TextColumn::make('user.username')
                    ->label('Author')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable()
                    ->color(fn (PostStatus $state): string => match ($state) {
                        PostStatus::Pending => 'warning',
                        PostStatus::Published => 'success',
                        PostStatus::Hidden => 'gray',
                        PostStatus::Rejected => 'danger',
                        PostStatus::Draft => 'gray',
                        PostStatus::Deleted => 'danger',
                    }),
                TextColumn::make('reports_count')
                    ->label('Reports')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('pending')
                    ->label('Pending')
                    ->query(fn (Builder $query) => $query->where('status', PostStatus::Pending)),
                Filter::make('published')
                    ->label('Published')
                    ->query(fn (Builder $query) => $query->where('status', PostStatus::Published)),
                Filter::make('hidden')
                    ->label('Hidden')
                    ->query(fn (Builder $query) => $query->where('status', PostStatus::Hidden)),
                Filter::make('reported')
                    ->label('Reported')
                    ->query(fn (Builder $query) => $query->where('reports_count', '>', 0)),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Post $record): bool => $record->status === PostStatus::Pending)
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Post $record, array $data): void {
                        app(ApprovePostAction::class)->handle(
                            auth()->user(),
                            $record,
                            $data['reason'] ?? null,
                        );
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Post $record): bool => $record->status === PostStatus::Pending)
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Post $record, array $data): void {
                        app(RejectPostAction::class)->handle(
                            auth()->user(),
                            $record,
                            $data['reason'] ?? null,
                        );
                    }),
                Action::make('hide')
                    ->label('Hide')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->visible(fn (Post $record): bool => $record->status === PostStatus::Published)
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Post $record, array $data): void {
                        app(HidePostAction::class)->handle(
                            auth()->user(),
                            $record,
                            $data['reason'] ?? null,
                        );
                    }),
            ])
            ->toolbarActions([
                //
            ]);
    }
}
