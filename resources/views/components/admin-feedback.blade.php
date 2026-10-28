@if(session('status'))
<div role="status" class="mb-5 rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</div>
@endif
@if($errors->any())
<div role="alert" class="mb-5 rounded-lg bg-red-50 p-4 text-red-800"><p class="font-semibold">Please review your changes.</p><ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
