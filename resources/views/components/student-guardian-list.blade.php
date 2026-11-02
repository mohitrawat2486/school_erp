@props(['links', 'historical' => false])
<ul class="student-guardian-list" aria-label="{{ $historical ? 'Previous guardian contacts' : 'Current guardian contacts' }}">
    @foreach($links as $link)
        @php
            $guardian = $link->guardian;
            $phone = preg_replace('/[^0-9+]/', '', $guardian->phone);
        @endphp
        <li class="student-guardian-row">
            <span class="student-guardian-avatar" aria-hidden="true">{{ mb_substr($guardian->first_name, 0, 1).mb_substr($guardian->last_name ?? '', 0, 1) }}</span>
            <div class="student-guardian-info">
                <div class="student-guardian-name"><h3>{{ $guardian->fullName() }}</h3><span>{{ ucfirst(strtolower($link->relationship->value)) }}</span></div>
                <div class="student-guardian-tags">
                    @if($historical)<span class="student-contact-tag">Previously linked</span>@elseif($link->is_primary)<span class="student-contact-tag is-primary">Primary contact</span>@else<span class="student-contact-tag">Additional contact</span>@endif
                    @if($link->is_emergency_contact && !$historical)<span class="student-contact-tag">Emergency contact</span>@endif
                    @if($guardian->status->value !== 'ACTIVE')<span class="student-contact-tag">Inactive guardian</span>@endif
                </div>
                <div class="student-guardian-contact"><x-icon name="phone" />@if(preg_match('/[0-9]/', $phone))<a href="tel:{{ $phone }}" aria-label="Call {{ $guardian->fullName() }} at {{ $guardian->phone }}">{{ $guardian->phone }}</a>@else<span>{{ $guardian->phone }}</span>@endif</div>
                @if($guardian->email)<div class="student-guardian-contact"><x-icon name="mail" /><a href="mailto:{{ rawurlencode($guardian->email) }}">{{ $guardian->email }}</a></div>@endif
                @if($guardian->alternate_phone || $guardian->occupation || $guardian->address)
                    <details class="student-guardian-more"><summary>More contact details</summary><dl>@foreach(['alternate_phone' => 'Alternate phone', 'occupation' => 'Occupation', 'address' => 'Address'] as $field => $label)@if($guardian->$field)<div><dt>{{ $label }}</dt><dd>{{ $guardian->$field }}</dd></div>@endif @endforeach</dl></details>
                @endif
            </div>
        </li>
    @endforeach
</ul>
