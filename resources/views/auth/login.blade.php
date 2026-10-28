<x-layout title="Sign in">
<main class="grid min-h-screen lg:grid-cols-2">
<section class="flex flex-col justify-center bg-[#692018] p-8 text-white lg:p-20"><img src="{{ asset('images/school-logo.png') }}" alt="School crest" class="mb-8 w-32 rounded-xl bg-white p-3"><p class="text-sm uppercase tracking-widest text-white/70">School administration</p><h1 class="mt-3 text-4xl font-bold">School ERP</h1><p class="mt-4 max-w-md text-lg text-white/80">A shared workspace for your school's academic records and results.</p></section>
<section class="flex items-center justify-center p-6"><div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-sm"><h2 class="text-2xl font-bold">Sign in</h2><p class="mt-2 mb-6 text-slate-500">Use your school account to continue.</p>
@if ($errors->any())<div role="alert" class="mb-5 rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ route('login.store') }}" class="space-y-5">@csrf
<div><label for="email" class="mb-2 block text-sm font-semibold">Email address</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="w-full rounded-lg border border-slate-300 p-3"></div>
<div><label for="password" class="mb-2 block text-sm font-semibold">Password</label><input id="password" name="password" type="password" required autocomplete="current-password" class="w-full rounded-lg border border-slate-300 p-3"></div>
<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Remember me</label>
<button class="w-full rounded-lg bg-[#8b2f24] p-3 font-semibold text-white hover:bg-[#692018] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#8b2f24]">Sign in</button>
</form></div></section></main></x-layout>
