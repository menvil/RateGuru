{{-- The site's icons, for every layout: the .ico for browsers that ask for
     /favicon.ico on their own, the SVG most browsers pick, a 32px PNG for the
     rest, the home-screen icon on iOS, and the web manifest, named from the
     project's settings, with the Android icons. --}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ route('site-manifest') }}">
