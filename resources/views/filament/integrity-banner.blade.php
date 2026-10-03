<div role="alert" style="background: #b91c1c; color: #fff; padding: 0.6rem 1rem; border-radius: 0.5rem; margin-bottom: 1rem; display: flex; gap: 0.75rem; align-items: center; justify-content: space-between; flex-wrap: wrap;">
    <span>
        <strong>{{ __('integrity.banner.title') }}</strong>
        {{ __('integrity.banner.body', ['count' => \App\Filament\Support\Display::digits($run->findings_count), 'time' => \App\Filament\Support\Display::dateTime($run->started_at)]) }}
    </span>
    <a href="{{ \App\Filament\Pages\Reports\IntegrityReport::getUrl() }}" style="color: #fff; text-decoration: underline; font-weight: 600;">
        {{ __('integrity.banner.link') }}
    </a>
</div>
