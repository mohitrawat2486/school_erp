<x-app-layout title="Report-card PDF"><a href="{{ route('report-pdfs.index') }}" class="text-sm font-semibold text-[#8b2f24]">← My PDF Downloads</a><h1 class="mt-4 mb-6 font-bold">Report-card PDF</h1><x-admin-feedback /><section class="rounded-xl border border-slate-200 bg-white p-6"><div class="mb-5"><x-status-badge :value="$job->expires_at->isPast()?'EXPIRED':$job->status" /></div><p class="mb-3 font-semibold">{{ $job->label }}</p><p>{{ $job->student_count }} student report cards · Requested {{ $job->created_at->format('d M Y H:i') }}</p>
@if($job->expires_at->isPast())
<p class="mt-4 text-slate-500">This download has expired. Request a new batch from Class-wise Results.</p>
@elseif($job->status==='READY')
<p class="mt-4 text-slate-500">Ready to download until {{ $job->expires_at->format('d M Y H:i') }}.</p><a href="{{ route('report-pdfs.download',$job) }}" class="mt-5 inline-flex rounded-lg bg-[#8b2f24] px-5 py-3 text-white">Download PDF</a>
@elseif($job->status==='FAILED')
<p class="mt-4 text-slate-500">The PDF could not be prepared. Check your access and request a new batch, or ask an administrator for help.</p>
@else
<p class="mt-4 text-slate-500">Your PDF is being prepared. Refresh this page to check its status.</p><a href="{{ route('report-pdfs.show',$job) }}" class="mt-5 inline-flex rounded-lg border border-slate-300 px-5 py-3">Refresh status</a>
@endif
</section><a href="{{ route('class-results.index') }}" class="mt-5 inline-flex text-sm font-semibold text-[#8b2f24]">Return to Class-wise Results →</a></x-app-layout>
