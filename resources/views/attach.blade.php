@if ($user = auth()->user())
    @foreach($socialProviders as $provider)
        @if ($user->isAttached($provider->slug))
            <form method="POST" action="{{ route('social.detach', [$provider->slug]) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-lg btn-danger btn-block {{ $provider->slug }}">
                    {{ $provider->label }}
                </button>
            </form>
        @else
            <a
                    href="{{ route('social.auth', [$provider->slug]) }}"
                    class="btn btn-lg btn-success btn-block {{ $provider->slug }}">
                {{ $provider->label }}
            </a>
        @endif
    @endforeach
@endif
