<x-workspace.layout title="Sign in · KhataPOS">
    <header class="border-b border-base-300 bg-base-100">
        <div class="mx-auto max-w-6xl px-4 py-4 sm:px-8"><x-workspace.brand /></div>
    </header>
    <main id="main-content" tabindex="-1" class="mx-auto grid max-w-6xl gap-5 px-4 py-8 sm:px-8 md:grid-cols-2 md:py-16">
        <section class="rounded-box bg-neutral p-6 text-neutral-content md:p-9">
            <h1 class="text-2xl font-bold tracking-tight sm:text-4xl">Shop workspace</h1>
            <p class="mt-3 max-w-sm leading-relaxed text-neutral-content/85">Sign in with your staff account to open your branch workspace.</p>
        </section>
        <section class="rounded-box border border-base-300 bg-base-100 p-6 md:p-9" aria-labelledby="sign-in-title">
            <h2 id="sign-in-title" class="mb-5 text-xl font-bold">Sign in</h2>
            <form method="post" action="{{ route('session.sign-in') }}" class="grid gap-4">
                @csrf
                <x-ui.field id="email" label="Email" :error="$errors->first('email')">
                    <input class="input w-full border-base-300!" id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required
                        @if ($errors->has('email')) autofocus aria-invalid="true" aria-describedby="email-error" @endif>
                </x-ui.field>
                <x-ui.field id="password" label="Password" :error="$errors->first('password')">
                    <input class="input w-full border-base-300!" id="password" name="password" type="password" autocomplete="current-password" required
                        @if ($errors->has('password') && ! $errors->has('email')) autofocus aria-invalid="true" aria-describedby="password-error" @endif>
                </x-ui.field>
                @if (config('auth.browser_pairing_required'))
                <x-ui.field id="pairing-code" label="Pairing code" :error="$errors->first('pairing_code')">
                    <input class="input w-full border-base-300!" id="pairing-code" name="pairing_code" autocomplete="off" spellcheck="false" maxlength="128"
                        @if (! request()->cookie('shop_device')) required @endif
                        @if ($errors->has('pairing_code') && ! $errors->has('email') && ! $errors->has('password')) autofocus aria-invalid="true" @endif
                        aria-describedby="pairing-hint{{ $errors->has('pairing_code') ? ' pairing-code-error' : '' }}">
                    <p id="pairing-hint" class="mt-2 text-sm text-base-content/75">
                        @if (request()->cookie('shop_device'))
                            Leave blank on an approved browser. Enter a fresh code if an owner has approved this browser again.
                        @else
                            Ask your shop owner for a one-time code to approve this browser.
                        @endif
                    </p>
                </x-ui.field>
                @endif
                <button type="submit" class="btn btn-primary mt-1">Sign in</button>
            </form>
            @if (config('auth.browser_pairing_required') && ! request()->cookie('shop_device'))
                <details class="mt-5 border-t border-base-300 pt-4 text-sm">
                    <summary class="cursor-pointer font-semibold">Setting up this shop?</summary>
                    <p class="mt-2 text-base-content/75">On the shop computer, run <code>php artisan shop:setup</code> from the application folder to create the first owner and get a pairing code.</p>
                </details>
            @endif
        </section>
    </main>
</x-workspace.layout>
