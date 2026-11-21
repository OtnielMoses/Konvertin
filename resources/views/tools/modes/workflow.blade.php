{{-- Create Workflow: susunan langkah. Pengaturan tiap langkah memakai partial alat aslinya. --}}
@php($workflowTools = collect(app(\App\Conversion\ToolCatalog::class)->workflowSteps())->keys()->map(fn ($slug) => app(\App\Conversion\ToolCatalog::class)->find($slug)))
<div class="preview-stage" data-workflow>
    <div class="file-list-head">
        <h2>Langkah workflow</h2>
        <span class="muted" data-workflow-count></span>
    </div>
    <ol class="wf-steps" data-workflow-steps></ol>
    <p class="empty-files" data-workflow-empty>Belum ada langkah. Tambahkan langkah pertama di bawah.</p>
    <div class="toolbar-row" style="margin-top: 12px;">
        <label for="wf-add" class="visually-hidden">Pilih alat untuk ditambahkan</label>
        <select class="select" id="wf-add" data-workflow-add-select style="flex: 1; min-width: 0;">
            @foreach ($workflowTools as $step)
                <option value="{{ $step->slug }}">{{ $step->name }}</option>
            @endforeach
        </select>
        <button type="button" class="btn btn-secondary" data-workflow-add><x-icon name="plus" size="18" />Tambah langkah</button>
    </div>

    @foreach ($workflowTools as $step)
        <template data-workflow-template="{{ $step->slug }}" data-workflow-name="{{ $step->name }}" data-workflow-icon="{{ $step->icon }}">
            @includeIf('tools.options.'.$step->slug, [
                'name' => fn ($field) => $field,
                'id' => fn ($field) => '__ID__-'.$field,
                'tool' => $step,
            ])
        </template>
    @endforeach
</div>
