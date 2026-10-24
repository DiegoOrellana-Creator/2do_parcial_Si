<x-app-layout>
    <x-slot name="header">
        <h2 class="page-title">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="page">
        <div class="panel">
            <div class="panel__inner">
                @include('profile.partials.update-profile-information-form')
            </div>
        </div>

        <div class="panel">
            <div class="panel__inner">
                @include('profile.partials.update-password-form')
            </div>
        </div>

        <div class="panel">
            <div class="panel__inner">
                @include('profile.partials.delete-user-form')
            </div>
        </div>
    </div>
</x-app-layout>
