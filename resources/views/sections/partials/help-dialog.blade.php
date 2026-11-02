<div data-class-help>
    <details class="class-help-fallback" data-class-help-fallback>
        <summary>How sections and class teachers work</summary>
        <div class="class-help-content" data-class-help-content>
            <section aria-labelledby="section-help-setup"><span class="class-help-icon"><x-icon name="sections" /></span><div><h3 id="section-help-setup">A section belongs to a class</h3><p>Use the <strong>add button</strong>, select a class, then enter a section name such as <strong>A</strong> or <strong>B</strong>. Capacity is the maximum number of students and can be left blank.</p><p>The <strong>section reference</strong> combines the class code and section name automatically.</p></div></section>
            <section aria-labelledby="section-help-teacher"><span class="class-help-icon"><x-icon name="teacher" /></span><div><h3 id="section-help-teacher">Give each section a class teacher</h3><p><strong>Pending</strong> means an active section still needs a class teacher for the selected academic year. Choose <strong>Assign</strong> to select one. An assignment covering the whole class also covers its sections.</p><p>Only one active class teacher can cover a section at a time. To change the teacher, open the teacher’s assignments and end the existing assignment before adding a replacement.</p></div></section>
            <section aria-labelledby="section-help-status"><span class="class-help-icon"><x-icon name="search" /></span><div><h3 id="section-help-status">Find sections quickly</h3><p>Search by section, class or reference. With assignment access, you can also search by class teacher. Narrow the list using <strong>Class</strong> and <strong>Status</strong>, or choose <strong>Reset</strong> to start again.</p><p><strong>Active sections</strong> includes those awaiting a teacher. <strong>Inactive sections</strong> keeps older records available. Overview cards count all sections; the unassigned count includes active sections in active classes.</p></div></section>
            <aside class="class-help-example"><h3>For example</h3><p><strong>Class 1 · Section A</strong> with class code <strong>CLASS-1</strong> has the reference <strong>CLASS-1-A</strong>. Assign Ms. Pooja as its class teacher for the selected academic year.</p></aside>
            <p class="class-help-note"><x-icon name="calendar" /><span>Class teachers can change each academic year. Choose the year before assigning a teacher. Closed years are available for viewing.</span></p>
        </div>
    </details>
    <dialog id="section-help-dialog" class="student-workflow-dialog class-help-dialog" data-class-help-dialog aria-labelledby="section-help-heading" aria-describedby="section-help-description">
        <div class="student-modal-header"><div><h2 id="section-help-heading" tabindex="-1">Sections &amp; class teachers</h2><p id="section-help-description">A quick guide to managing your sections.</p></div><button type="button" class="icon-button" data-class-help-close aria-label="Close sections guide"><x-icon name="close" /></button></div>
        <div class="student-modal-content" data-class-help-slot></div>
        <div class="student-modal-footer"><button type="button" class="button-primary" data-class-help-close>Got it</button></div>
    </dialog>
</div>
