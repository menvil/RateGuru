<div data-testid="report-modal">
    @foreach($this->reasons as $reason)
        <span>{{ $reason['label'] }}</span>
    @endforeach
</div>
