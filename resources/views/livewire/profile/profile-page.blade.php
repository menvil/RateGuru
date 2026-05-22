<div data-testid="profile-page">
    <h1>{{ $profileUser->name ?: $profileUser->username }}</h1>
    <p>{{ '@' . $profileUser->username }}</p>
</div>
