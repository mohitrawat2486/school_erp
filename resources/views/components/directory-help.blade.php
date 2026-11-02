@props(['id', 'title', 'description', 'summary'])
<div data-class-help>
    <details class="class-help-fallback" data-class-help-fallback>
        <summary>{{ $summary }}</summary>
        <div class="class-help-content" data-class-help-content>{{ $slot }}</div>
    </details>
    <dialog id="{{ $id }}" class="student-workflow-dialog class-help-dialog" data-class-help-dialog aria-labelledby="{{ $id }}-heading" aria-describedby="{{ $id }}-description">
        <div class="student-modal-header"><div><h2 id="{{ $id }}-heading" tabindex="-1">{{ $title }}</h2><p id="{{ $id }}-description">{{ $description }}</p></div><button type="button" class="icon-button" data-class-help-close aria-label="Close guide"><x-icon name="close" /></button></div>
        <div class="student-modal-content" data-class-help-slot></div>
        <div class="student-modal-footer"><button type="button" class="button-primary" data-class-help-close>Got it</button></div>
    </dialog>
</div>
