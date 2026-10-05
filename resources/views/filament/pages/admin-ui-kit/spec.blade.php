{{-- One element card: ID, name, source, purpose, live specimen, specs, code and rules. --}}
<section
    id="{{ $spec['id'] }}"
    class="rg-admin-kit__spec"
    aria-labelledby="{{ $spec['id'] }}-name"
    x-show="matches(@js($spec['search']))"
>
    <x-admin.ui.card flush>
        <div class="rg-admin-kit__spec-head">
            <a href="#{{ $spec['id'] }}" class="rg-admin-kit__spec-id" title="Link to {{ $spec['id'] }}">{{ $spec['id'] }}</a>
            <div class="rg-admin-kit__spec-heading">
                <div class="rg-admin-kit__spec-title-row">
                    <h3 id="{{ $spec['id'] }}-name" class="rg-admin-kit__spec-name">{{ $spec['name'] }}</h3>
                    <span @class(['rg-admin-kit__spec-source', 'rg-admin-kit__spec-source--component' => $spec['kind'] === 'component'])>{{ $spec['source'] }}</span>
                </div>
                <p class="rg-admin-kit__spec-purpose">{{ $spec['purpose'] }}</p>
            </div>
        </div>

        <div class="rg-admin-kit__specimen">{{ $slot }}</div>

        <div class="rg-admin-kit__spec-details">
            <div class="rg-admin-kit__spec-column">
                <div class="rg-admin-section-label"><span class="rg-admin-section-label__text">Specs</span></div>
                <dl class="rg-admin-kit__spec-table">
                    @foreach ($spec['specs'] as [$key, $value])
                        <div class="rg-admin-kit__spec-row">
                            <dt class="rg-admin-kit__spec-key">{{ $key }}</dt>
                            <dd class="rg-admin-kit__spec-value">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
            <div class="rg-admin-kit__spec-column">
                <div class="rg-admin-section-label"><span class="rg-admin-section-label__text">Usage</span></div>
                <pre class="rg-admin-kit__code"><code>{{ $spec['code'] }}</code></pre>
            </div>
        </div>

        @if ($spec['rules'] !== [])
            <div class="rg-admin-kit__rules">
                <div class="rg-admin-section-label"><span class="rg-admin-section-label__text">Rules</span></div>
                <ul class="rg-admin-kit__rule-list">
                    @foreach ($spec['rules'] as $rule)
                        @php
                            $prohibition = str_starts_with($rule, '!');
                        @endphp
                        <li class="rg-admin-kit__rule">
                            <x-admin.ui.icon :name="$prohibition ? 'x' : 'check'" :size="14" :label="$prohibition ? 'Never' : 'Do'" />
                            <span>{{ $prohibition ? substr($rule, 1) : $rule }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-admin.ui.card>
</section>
