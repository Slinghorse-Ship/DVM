(() => {
    'use strict';
    if (window.KulturhusDocumentWorkflows) {
        window.KulturhusDocumentWorkflows.init(document);
        return;
    }

    const creationTextCache = new WeakMap();
    const creationText = (source, context) => {
        const workspace = context.closest('[data-dwf-workspace]');
        if (!workspace) return source;
        if (!creationTextCache.has(workspace)) {
            try { creationTextCache.set(workspace, JSON.parse(workspace.dataset.dwfCreationText || '{}')); }
            catch { creationTextCache.set(workspace, {}); }
        }
        return creationTextCache.get(workspace)[source] ?? source;
    };
    const creationFormat = (source, context, values) => {
        let result = creationText(source, context);
        for (const [key, value] of Object.entries(values)) result = result.replaceAll('{'+key+'}', String(value));
        return result;
    };
    const controlText = (control, context) => !control ? '' : control.type === 'checkbox'
        ? creationText(control.checked ? 'Bestätigt' : 'Nicht bestätigt', context)
        : control instanceof HTMLSelectElement ? (control.value ? control.selectedOptions[0]?.textContent || '' : '') : control.value;
    const editors = root => [...root.querySelectorAll('[data-dwf-template-editor]')];
    const rows = editor => [...editor.querySelector('[data-dwf-fields]').children].filter(row => row.matches('[data-dwf-field]'));
    const announce = (editor, text) => { editor.querySelector('[data-dwf-editor-status]').textContent = creationText(text, editor); };
    const element = (tag, className = '', text = '') => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== '') node.textContent = text;
        return node;
    };
    const riskValue = matrix => {
        const likelihood = matrix.querySelector('[data-dwf-axis="likelihood"]').value;
        const severity = matrix.querySelector('[data-dwf-axis="severity"]').value;
        return /^[1-5]$/.test(likelihood) && /^[1-5]$/.test(severity)
            ? {likelihood: Number(likelihood), severity: Number(severity)} : null;
    };
    const riskText = (value, context) => value
        ? creationFormat('Risikowert: {score} ({likelihood} × {severity})', context, {score:value.likelihood*value.severity, likelihood:value.likelihood, severity:value.severity})
        : creationText('Bewertung noch offen', context);
    function refreshMatrix(matrix) {
        const value = riskValue(matrix);
        matrix.querySelector('[data-dwf-matrix-grid]').hidden = false;
        matrix.querySelectorAll('[data-dwf-risk-likelihood]').forEach(cell => {
            cell.setAttribute('aria-pressed', String(Boolean(value && Number(cell.dataset.dwfRiskLikelihood) === value.likelihood && Number(cell.dataset.dwfRiskSeverity) === value.severity)));
        });
        matrix.querySelector('[data-dwf-risk-score]').textContent = riskText(value, matrix);
    }
    function matrixPreview(value = null, context) {
        const wrapper=element('div','dwf-preview-matrix'),table=element('table','dwf-matrix-table dwf-preview-matrix-table');table.append(element('caption','',creationText('Risikomatrix · R = S × L',context)));const head=element('thead'),header=element('tr');['S / L','1','2','3','4','5'].forEach(text=>header.append(element('th','',text)));head.append(header);table.append(head);const body=element('tbody'),profile=riskProfile('rwe_reference_de_v1',context);
        for(let severity=5;severity>=1;severity--){const row=element('tr'),label=element('th','',String.fromCharCode(64+severity));label.scope='row';row.append(label);for(let likelihood=1;likelihood<=5;likelihood++){const selected=value&&likelihood===value.likelihood&&severity===value.severity,score=severity*likelihood,band=profile.bands.find(b=>score>=b.min&&score<=b.max),cell=element('td',selected?'dwf-matrix-selected':'',String(score));cell.style.setProperty('background',band.background,'important');cell.style.setProperty('color','#000','important');row.append(cell)}body.append(row)}table.append(body);wrapper.append(table,element('p','dwf-risk-score',riskText(value,context)),element('small','dwf-matrix-note',creationText('R = S × L. Grün: 1–4, Gelb: 5–9, Rot: 10–25. Die Freigabe erfolgt gesondert.',context)));return wrapper;
    }
    const registerTextColumns = [['category', 'Gefährdungskategorie'], ['hazard', 'Gefährdung'], ['impact', 'Auswirkung'], ['controls', 'Schutzmaßnahmen'], ['responsible', 'Verantwortlich'], ['effectiveness', 'Wirksamkeitsprüfung']];
    const registerDateColumns = [['action_deadline', 'Maßnahmenfrist'], ['action_completed_on', 'Erledigt am']];
    const registerRows = register => [...register.querySelector('[data-dwf-register-rows]').children].filter(row => row.matches('[data-dwf-register-row]'));
    const registerControl = (row, path) => [...row.querySelectorAll('[data-dwf-register-path]')].find(control => control.dataset.dwfRegisterPath === path);
    const registerRisk = (row, column) => ({
        severity: Number(registerControl(row, `${column}][severity`).value) || null,
        likelihood: Number(registerControl(row, `${column}][likelihood`).value) || null
    });
    const completeRisk = risk => risk && Number.isInteger(risk.severity) && risk.severity >= 1 && risk.severity <= 5 && Number.isInteger(risk.likelihood) && risk.likelihood >= 1 && risk.likelihood <= 5;
    const riskSeverity = risk => risk?.severity ? String.fromCharCode(64 + risk.severity) : '–';
    const riskScore = risk => completeRisk(risk) ? String(risk.severity * risk.likelihood) : '–';
    const profileCache = new WeakMap();
    const profileContexts = new WeakMap();
    const riskUi = (source, profile) => creationText(source, profileContexts.get(profile));
    function riskProfile(profileId, context) {
        const workspace = context.closest('[data-dwf-workspace]');
        if (!profileCache.has(workspace)) profileCache.set(workspace, JSON.parse(workspace.dataset.dwfRiskProfiles));
        const profile = profileCache.get(workspace)[profileId || 'rwe_reference_de_v1'];
        if (!profile) throw new Error(creationText('Das Risikomatrix-Profil ist nicht verfügbar.', context));
        profileContexts.set(profile, workspace);
        return profile;
    }
    function configuredRisk(risk, profile) {
        if (!completeRisk(risk)) return null;
        return profile.cells.flat().find(cell => cell.severity === risk.severity && cell.likelihood === risk.likelihood) || null;
    }
    function decorateRisk(node, risk, profile, showLabel = true) {
        const cell = configuredRisk(risk, profile);
        node.classList.remove('dwf-risk-band-low', 'dwf-risk-band-medium', 'dwf-risk-band-high');
        node.textContent = cell ? String(cell.score) : '–';
        if (cell?.band) {
            node.classList.add(`dwf-risk-band-${cell.band}`);
            if (showLabel) node.append(element('small', 'dwf-risk-band-label', cell.band_label));
            if (node.classList.contains('dwf-rwe-score')) {
                node.style.setProperty('background', profile.bands.find(band => band.key === cell.band).background, 'important');
                node.style.setProperty('color', '#000', 'important');
            }
        }
        const label = cell ? creationFormat('{severity} × {likelihood}: Risikowert {score}{band}', profileContexts.get(profile), {severity:riskSeverity(risk),likelihood:risk.likelihood,score:cell.score,band:cell.band_label?', '+cell.band_label:''}) : riskUi('Bewertung noch offen', profile);
        node.setAttribute('aria-label', label);
        node.title = label;
    }
    function readRegister(register) {
        return registerRows(register).map(row => {
            const value = {};
            registerTextColumns.forEach(([column]) => { value[column] = registerControl(row, column).value; });
            registerDateColumns.forEach(([column]) => { const date = registerControl(row, column)?.value; if (date) value[column] = date; });
            value.initial = registerRisk(row, 'initial'); value.residual = registerRisk(row, 'residual');
            return value;
        });
    }
    function refreshRegister(register) {
        const records = registerRows(register);
        const profile = riskProfile(register.dataset.dwfMatrixProfile || '', register);
        records.forEach((row, index) => {
            row.querySelector('[data-dwf-row-number]').textContent = String(index + 1);
            row.querySelectorAll('[data-dwf-register-path]').forEach(control => {
                control.name = `${register.dataset.dwfRegisterName}[${index}][${control.dataset.dwfRegisterPath}]`;
                control.setAttribute('aria-label', creationFormat('Zeile {row}: {column}', register, {row:index+1,column:control.dataset.dwfColumnLabel}));
            });
            const up = row.querySelector('[data-dwf-register-move="up"]'), down = row.querySelector('[data-dwf-register-move="down"]');
            if (up) up.disabled = index === 0;
            if (down) down.disabled = index === records.length - 1;
            if (up) up.value = `register_up:${register.dataset.dwfRegisterField}:${index}`;
            if (down) down.value = `register_down:${register.dataset.dwfRegisterField}:${index}`;
            const remove = row.querySelector('[data-dwf-register-remove]');
            if (remove) remove.value = `register_remove:${register.dataset.dwfRegisterField}:${index}`;
            const origin = row.querySelector('[data-dwf-register-origin]');
            if (origin) origin.name = `${register.dataset.dwfRegisterName}[${index}][__origin]`;
            row.querySelectorAll('[data-dwf-register-score]').forEach(output => decorateRisk(output, registerRisk(row, output.dataset.dwfRegisterScore), profile));
            row.querySelectorAll('select[data-dwf-register-path]').forEach(select => {
                const severityAxis = select.dataset.dwfRegisterPath.endsWith('severity');
                const levels = severityAxis ? profile.severity : profile.likelihood;
                [...select.options].forEach(option => {
                    const level = levels.find(candidate => String(candidate.value) === option.value);
                    if (level) option.title = `${level.label}: ${level.description}`;
                });
            });
        });
        const add = register.querySelector('[data-dwf-register-add]');
        if (add) add.disabled = records.length >= 30;
        const countInput = register.querySelector('[data-dwf-register-count-input]');
        if (countInput) countInput.value = String(records.length);
        const count = register.querySelector('[data-dwf-register-count]');
        if (count) count.textContent = creationFormat('{count} von 30 Zeilen', register, {count:records.length});
    }
    function registerLegend(profile) {
        if (profile.id === 'rwe_reference_de_v1') return rweLegend(profile);
        const legend = element('aside', 'dwf-register-legend');
        legend.append(element('strong', 'dwf-register-legend-title', riskUi('Risikomatrix · R = S × L', profile)));
        legend.append(element('p', 'dwf-register-legend-formula', riskUi('S: Schadensschwere A = 1, B = 2, C = 3, D = 4, E = 5. L: Wahrscheinlichkeit 1–5. Gleicher Maßstab für Ausgangs- und Restrisiko.', profile)));
        const composite = element('div', 'dwf-register-legend-composite dwf-register-legend-mini');
        const table = element('table', 'dwf-register-legend-table'), head = element('thead'), header = element('tr');
        const corner = element('th', 'dwf-register-severity-heading', 'S / L'); corner.scope = 'col'; header.append(corner);
        profile.likelihood.forEach(level => {
            const cell = element('th', 'dwf-register-likelihood-heading'); cell.scope = 'col';
            cell.append(element('strong', '', String(level.value)));
            header.append(cell);
        });
        head.append(header); table.append(head);
        const body = element('tbody');
        profile.severity.forEach(level => {
            const row = element('tr'), label = element('th', 'dwf-register-severity-definition'); label.scope = 'row';
            label.append(element('strong', '', `${level.letter}`)); row.append(label);
            profile.likelihood.forEach(likelihood => {
                const cell = element('td'); decorateRisk(cell, {severity: level.value, likelihood: likelihood.value}, profile, false); row.append(cell);
            });
            body.append(row);
        });
        table.append(body); composite.append(table);
        const definitions = (levels, title, severity) => {
            const block = element('div', 'dwf-register-axis-definitions'); block.append(element('strong', '', title));
            levels.forEach(level => { const item = element('p'); item.append(element('b', '', `${severity ? level.letter + ' (' + level.value + ')' : level.value} ${level.label}`), document.createTextNode(' · ' + level.description)); block.append(item); });
            return block;
        };
        composite.append(definitions(profile.severity, riskUi('Schadensschwere S', profile), true), definitions(profile.likelihood, riskUi('Wahrscheinlichkeit L', profile), false));
        if (profile.bands.length) {
            const bands = element('table', 'dwf-register-band-table'), bandHead = element('thead'), bandHeader = element('tr');
            ['Risikobereich R', 'Bedeutung'].forEach(text => { const cell = element('th', '', riskUi(text, profile)); cell.scope = 'col'; bandHeader.append(cell); });
            bandHead.append(bandHeader); bands.append(bandHead);
            const bandBody = element('tbody');
            profile.bands.forEach(band => {
                const row = element('tr'), label = element('th', `dwf-risk-band-${band.key}`); label.scope = 'row';
                label.append(element('strong', '', band.label), element('span', '', `${band.min}–${band.max}`));
                row.append(label, element('td', '', band.description)); bandBody.append(row);
            });
            bands.append(bandBody); composite.append(bands);
        } else composite.append(element('p', 'dwf-register-neutral-description', riskUi('Neutrales Profil ohne festgelegte Farbbänder. Die Bedeutung der Stufen und Bewertungsgrenzen muss betrieblich festgelegt werden.', profile)));
        legend.append(composite);
        return legend;
    }
    function rweLegend(profile) {
        const legend=element('aside','dwf-rwe-legend'), main=element('div','dwf-rwe-legend-main');
        main.append(element('strong','',riskUi('Risikomatrix', profile)));
        const table=element('table','dwf-rwe-matrix'), cols=element('colgroup');
        [17.655,19.368,9.354,3.03,10.119,10.119,10.119,10.119,10.119].forEach(width=>{const col=element('col');col.style.width=width+'%';cols.append(col);});table.append(cols);
        const head=element('thead'), header=element('tr');
        ['UMWELT','SICHERHEIT','S',''].forEach(text=>header.append(element('th','',riskUi(text, profile))));
        profile.likelihood.forEach(level=>{const cell=element('th');cell.append(element('b','',level.label),element('span','',level.description),element('b','',String(level.value)));header.append(cell);});
        head.append(header);table.append(head);const body=element('tbody');
        profile.severity.forEach(level=>{const row=element('tr');[level.environment,level.description,level.label].forEach(text=>row.append(element('td','',text)));
            row.append(element('th','',level.letter));profile.likelihood.forEach(likelihood=>{const cell=element('td','dwf-rwe-score');decorateRisk(cell,{severity:level.value,likelihood:likelihood.value},profile,false);row.append(cell);});body.append(row);});
        table.append(body);const foot=element('tfoot'), frequency=element('tr'), gap=element('td');gap.colSpan=4;frequency.append(gap);
        profile.likelihood.forEach(level=>frequency.append(element('td','',level.frequency)));foot.append(frequency);table.append(foot);main.append(table);
        const bands=element('table','dwf-rwe-bands'), bh=element('thead'), hr=element('tr');['Risiko','Wert','Bedeutung','Freigabe'].forEach(text=>hr.append(element('th','',riskUi(text, profile))));bh.append(hr);bands.append(bh);
        const bb=element('tbody');[...profile.bands].reverse().forEach(band=>{const row=element('tr');row.append(element('th',`dwf-risk-band-${band.key}`,band.label),element('td','',`${band.min}–${band.max}`),element('td','',band.description),element('td','',band.approver));bb.append(row);});bands.append(bb);legend.append(main,bands);return legend;
    }
    function rweHeader(form, template) {
        const values={};
        if (!template) form.querySelectorAll('[data-dwf-value-field]').forEach(field=>{const input=field.querySelector('input:not([type=hidden]),textarea,select');if(input)values[field.dataset.dwfKey||input.name.match(/\[([^\]]+)\]/)?.[1]]=controlText(input,form);});
        const table=element('table','dwf-rwe-metadata'), head=element('thead'), heading=element('tr'), title=element('th','',creationText('DVM · Gefährdungsbeurteilung',form));title.colSpan=4;heading.append(title);head.append(heading);table.append(head);
        const cols=element('colgroup');[13.739,29.418,14.735,42.108].forEach(width=>{const c=element('col');c.style.width=width+'%';cols.append(c);});table.append(cols);
        const body=element('tbody');
        const row=(pairs)=>{const tr=element('tr');pairs.forEach(([label,value,span=1])=>{tr.append(element('th','',creationText(label,form)));const td=element('td','',value||'—');td.colSpan=span;tr.append(td);});body.append(tr);};
        row([['Dokument-Nr.',values.document_ref],['Titel der Beurteilung',values.assessment_title||form.elements.wf_title.value]]);
        row([['Revision',form.elements.wf_version?.value||'1',3]]);
        row([['Projekt / Standort',values.location],['Arbeit / Tätigkeit',values.activity]]);
        row([['Arbeitsmittel / Anlage',values.equipment],['Arbeitsschritt',values.task_step||values.work_phase]]);
        row([['Beurteilung durch',values.assessor,3]]);row([['Freigabe durch',values.approver,3]]);
        row([['Freigabedatum',values.approval_date],['Prüftermin',values.review_date]]);table.append(body);return table;
    }
    function rweAcknowledgement(form) {
        const section=element('section','dwf-rwe-acknowledgement');section.append(element('h5','',creationText('Ursprüngliche Erklärung der Referenzvorlage',form)));section.append(element('p','','Ich bestätige, dass ich die Gefährdungen, denen ich ausgesetzt sein kann, sowie ihre möglichen Auswirkungen, Wahrscheinlichkeit und Schwere gelesen und verstanden habe. Ich werde die mitgeteilten Schutzmaßnahmen soweit praktikabel befolgen. Sind die Maßnahmen nicht ausreichend, stelle ich die Arbeit ein und informiere die verantwortliche Leitung.'));
        const lines=element('div','dwf-rwe-signatures');['Name','Datum','Unterschrift'].forEach(text=>lines.append(element('span','',creationText(text,form))));section.append(lines);return section;
    }
    function registerTable(records, label, profile) {
        const wrap = element('section', 'dwf-register-print-section');
        wrap.append(element('h5', '', label || riskUi('Gefährdungsregister',profile)));
        const table = element('table', 'dwf-register-print-table');
        const colgroup = element('colgroup');
        (profile.id==='rwe_reference_de_v1'?[7.3,15.5,11,3.3,3.3,3.3,26.7,3.1,3.1,3.1,9.6,10.7]:[7,11,10,3,3,3,24,3,3,3,10,10]).forEach(width => { const col = element('col'); col.style.width = `${width}%`; colgroup.append(col); });
        table.append(colgroup);
        const head = element('thead'), first = element('tr'), second = element('tr');
        ['Gefährdungskategorie', 'Gefährdung', 'Auswirkung', 'Ausgangsrisiko', 'Schutzmaßnahmen', 'Restrisiko', 'Verantwortlich', 'Wirksamkeitsprüfung'].forEach(text => {
            const cell = element('th', '', riskUi(text,profile));
            if (text === 'Ausgangsrisiko' || text === 'Restrisiko') { cell.colSpan = 3; cell.scope = 'colgroup'; }
            else { cell.rowSpan = 2; cell.scope = 'col'; }
            first.append(cell);
        });
        ['S', 'L', 'R', 'S', 'L', 'R'].forEach(text => { const cell = element('th', '', riskUi(text,profile)); cell.scope = 'col'; second.append(cell); });
        head.append(first, second); table.append(head);
        const body = element('tbody');
        const displayRows = records.length ? records : [{}];
        displayRows.forEach(record => {
            const row = element('tr');
            ['category', 'hazard', 'impact'].forEach(column => row.append(element('td', '', record[column] || '—')));
            const addRisk = value => {
                const score = element('td', 'dwf-register-print-risk dwf-register-print-score'); decorateRisk(score, value, profile,profile.id!=='rwe_reference_de_v1');
                row.append(element('td', 'dwf-register-print-risk', riskSeverity(value)), element('td', 'dwf-register-print-risk', value?.likelihood ? String(value.likelihood) : '–'), score);
            };
            addRisk(record.initial);
            let measures = record.controls || '—';
            registerDateColumns.forEach(([column, label]) => { if (record[column]) measures += `\n${riskUi(label,profile)}: ${record[column]}`; });
            row.append(element('td', '', measures));
            addRisk(record.residual);
            ['responsible', 'effectiveness'].forEach(column => row.append(element('td', '', record[column] || '—')));
            body.append(row);
        });
        table.append(body); wrap.append(table);
        return wrap;
    }
    function previewRiskSheet(form, paper, template = false) {
        const sheet = element('article', 'dwf-risk-sheet');
        const heading = element('header', 'dwf-risk-sheet-heading');
        heading.append(element('span', 'dwf-risk-sheet-brand', creationText('DVM · Dokumente',form)), element('h4', '', form.elements.wf_title.value || creationText('Gefährdungsbeurteilung',form)));
        sheet.append(heading);
        const top = element('div', 'dwf-risk-sheet-top'), metadata = element('dl', 'dwf-risk-sheet-metadata');
        const groups = [];
        if (template) rows(form).forEach(row => {
            const type = row.querySelector('[data-dwf-type]').value, label = row.querySelector('[data-dwf-label]').value || creationText('Unbenanntes Feld',form);
            if (type === 'risk_register') groups.push({label, records: [], profileId: row.querySelector('select[data-dwf-matrix-profile]').value});
            else { const term=element('dt','',label);term.dataset.fieldKey=row.querySelector('[data-dwf-key]')?.value||'';metadata.append(term, element('dd', 'dwf-preview-empty', '—')); }
        });
        else form.querySelectorAll('[data-dwf-value-field]').forEach(field => {
            if (field.dataset.dwfType === 'risk_register') {
                const register = field.querySelector('[data-dwf-register]');
                groups.push({label: field.dataset.dwfLabel, records: readRegister(register), profileId: register.dataset.dwfMatrixProfile || ''}); return;
            }
            const control = field.querySelector('input:not([type=hidden]), textarea, select');
            let value = controlText(control,form);
            if (field.dataset.dwfType === 'risk_matrix') value = riskText(riskValue(field.querySelector('[data-dwf-matrix]')),form);
            const term=element('dt','',field.dataset.dwfLabel);term.dataset.fieldKey=field.dataset.dwfKey||control?.name.match(/\[([^\]]+)\]/)?.[1]||'';metadata.append(term, element('dd', '', value || '—'));
        });
        if (!metadata.childNodes.length) metadata.append(element('dt', '', creationText('Dokumenttitel',form)), element('dd', '', form.elements.wf_title.value || '—'));
        const legends = element('div', 'dwf-register-legends');
        [...new Set(groups.map(group => group.profileId))].forEach(profileId => legends.append(registerLegend(riskProfile(profileId, form))));
        const rwe=groups.some(group=>group.profileId==='rwe_reference_de_v1');
        if(rwe){sheet.classList.add('dwf-rwe-sheet');heading.remove();top.append(rweHeader(form,template),legends);}else top.append(metadata,legends);
        sheet.append(top);
        groups.forEach(group => sheet.append(registerTable(group.records, group.label, riskProfile(group.profileId, form))));
        if(rwe){const represented=new Set(['document_ref','assessment_title','location','activity','equipment','task_step','work_phase','assessor','approver','approval_date','review_date']);const additional=element('dl','dwf-rwe-additional-fields');for(const term of metadata.querySelectorAll('dt'))if(!represented.has(term.dataset.fieldKey)){additional.append(term.cloneNode(true),term.nextElementSibling.cloneNode(true));}if(additional.childNodes.length)sheet.append(additional);}
        if(rwe)sheet.append(rweAcknowledgement(form));
        paper.append(sheet);
    }
    function previewTemplate(form, paper) {
        if (rows(form).some(row => row.querySelector('[data-dwf-type]').value === 'risk_register')) { previewRiskSheet(form, paper, true); return; }
        paper.append(element('h4', 'dwf-preview-title', form.elements.wf_title.value || creationText('Unbenannte Vorlage', form)));
        if (form.elements.wf_description.value) paper.append(element('p', 'dwf-preview-description', form.elements.wf_description.value));
        const fields = rows(form);
        if (!fields.length) paper.append(element('p', 'dwf-muted', creationText('Noch keine Formularfelder.', form)));
        fields.forEach(row => {
            const type = row.querySelector('[data-dwf-type]').value;
            const label = row.querySelector('[data-dwf-label]').value || creationText('Unbenanntes Feld', form);
            const required = row.querySelector('[name$="[required]"]').checked;
            const help = row.querySelector('[name$="[help]"]').value;
            const section = element('section', 'dwf-preview-field');
            section.append(element('h5', '', label + (required ? ' *' : '')));
            if (type === 'risk_matrix') section.append(matrixPreview(null, form));
            else if (type === 'checkbox') {
                const checkbox = element('input'); checkbox.type = 'checkbox'; checkbox.disabled = true;
                checkbox.setAttribute('aria-label', label);
                const choice = element('label', 'dwf-check'); choice.append(checkbox, document.createTextNode(creationText('Bestätigt', form)));
                section.append(choice);
            } else {
                const control = element(type === 'textarea' ? 'textarea' : type === 'select' ? 'select' : 'input');
                if (control instanceof HTMLInputElement) control.type = ['date', 'number'].includes(type) ? type : 'text';
                if (control instanceof HTMLTextAreaElement) control.rows = 3;
                if (control instanceof HTMLSelectElement) {
                    control.append(element('option', '', creationText('Bitte auswählen', form)));
                    row.querySelector('[name$="[options]"]').value.split(/\r?\n/).map(text => text.trim()).filter(Boolean).forEach(text => control.append(element('option', '', text)));
                    // Show choices directly, since a disabled select cannot be opened in the preview.
                    section.append(element('p', 'dwf-preview-options', [...control.options].slice(1).map(option => option.text).join(' · ') || creationText('Noch keine Auswahlmöglichkeiten', form)));
                }
                control.disabled = true; control.setAttribute('aria-label', label);
                section.append(control);
            }
            if (help) section.append(element('small', '', help));
            paper.append(section);
        });
    }
    function previewDocument(form, paper) {
        if (form.querySelector('[data-dwf-register]')) { previewRiskSheet(form, paper); return; }
        paper.append(element('h4', 'dwf-preview-title', form.elements.wf_title.value || creationText('Unbenannter Entwurf',form)));
        const folder = form.elements.wf_folder;
        paper.append(element('p', 'dwf-preview-description', creationFormat('Ablage: {folder}',form,{folder:folder.selectedOptions[0]?.textContent || creationText('Ohne Ordner',form)})));
        form.querySelectorAll('[data-dwf-value-field]').forEach(field => {
            const section = element('section', 'dwf-preview-field');
            section.append(element('h5', '', field.dataset.dwfLabel + (field.dataset.dwfRequired === '1' ? ' *' : '')));
            if (field.dataset.dwfType === 'risk_matrix') section.append(matrixPreview(riskValue(field.querySelector('[data-dwf-matrix]')),form));
            else {
                const control = field.querySelector('input:not([type=hidden]), textarea, select');
                const value = controlText(control,form);
                section.append(element('p', value ? 'dwf-preview-value' : 'dwf-preview-value dwf-preview-empty', value || creationText('Noch offen',form)));
            }
            paper.append(section);
        });
    }
    function renderPreview(form) {
        const preview = form.closest('.dwf-live-layout')?.querySelector('[data-dwf-preview]');
        if (!preview) return;
        const content = preview.querySelector('[data-dwf-preview-content]');
        const scrollTop = preview.scrollTop;
        const paper = document.createDocumentFragment();
        const register = form.matches('[data-dwf-template-editor]') ? rows(form).some(row => row.querySelector('[data-dwf-type]').value === 'risk_register') : Boolean(form.querySelector('[data-dwf-register]'));
        form.closest('.dwf-live-layout').classList.toggle('dwf-register-layout', register);
        if (form.matches('[data-dwf-template-editor]')) previewTemplate(form, paper);
        else previewDocument(form, paper);
        content.replaceChildren(paper);
        preview.hidden = false;
        preview.scrollTop = scrollTop;
    }
    function refresh(editor) {
        const fields = rows(editor);
        fields.forEach((row, index) => {
            row.querySelector('[data-dwf-field-number]').textContent = String(index + 1);
            row.querySelectorAll('[name]').forEach(control => {
                control.name = control.name.replace(/^wf_fields\[[^\]]+\]/, `wf_fields[${index}]`);
            });
            row.querySelector('[data-dwf-move="up"]').disabled = index === 0;
            row.querySelector('[data-dwf-move="down"]').disabled = index === fields.length - 1;
            row.querySelector('[data-dwf-move="up"]').value = `up:${index}`;
            row.querySelector('[data-dwf-move="down"]').value = `down:${index}`;
            row.querySelector('[data-dwf-remove]').value = `remove:${index}`;
            const options = row.querySelector('[data-dwf-options]');
            const isSelect = row.querySelector('[data-dwf-type]').value === 'select';
            options.hidden = !isSelect;
            options.querySelector('textarea').disabled = !isSelect;
            const profileSetting = row.querySelector('[data-dwf-profile-setting]');
            const isRegister = row.querySelector('[data-dwf-type]').value === 'risk_register';
            profileSetting.hidden = !isRegister;
            profileSetting.querySelector('select').disabled = !isRegister;
        });
        editor.querySelector('[data-dwf-add]').disabled = fields.length >= 100;
        const count = editor.querySelector('[name=wf_field_count]');
        if (count) count.value = String(fields.length);
        renderPreview(editor);
    }
    const changed = editor => editor.dispatchEvent(new Event('input', {bubbles: true}));
    const dirtyForms = root => [...root.querySelectorAll('[data-dwf-dirty="1"]')];
    const signatureForms = new WeakSet();
    function initSignatures(root) {
        root.querySelectorAll('[data-dwf-signature-form]').forEach(form => {
            if (signatureForms.has(form)) return;
            signatureForms.add(form);
            const canvas = form.querySelector('[data-dwf-signature-canvas]');
            let context = null;
            try { context = canvas?.getContext('2d'); } catch {}
            const error = form.querySelector('[data-dwf-signature-error]'), status = form.querySelector('[data-dwf-signature-status]');
            const imageInput = form.elements.wf_signature_png, file = form.elements.wf_signature_file;
            const retainedImage = imageInput.value, accepted = form.elements.wf_accepted;
            const drawChoice = form.querySelector('[data-dwf-signature-draw-choice]'), drawArea = form.querySelector('[data-dwf-signature-draw]');
            const radios = [...form.querySelectorAll('[name=wf_signature_method]')];
            let pointer = null, ink = false;
            const method = () => radios.find(input => input.checked)?.value || 'draw';
            const dirty = () => {
                if (ink || retainedImage || file?.files?.length || accepted?.checked) form.dataset.dwfDirty = '1';
                else delete form.dataset.dwfDirty;
            };
            const sync = () => {
                if (drawArea) drawArea.hidden = method() !== 'draw';
                if (file) { file.disabled = method() !== 'upload'; file.required = method() === 'upload'; }
                error.textContent = ''; dirty();
            };
            const reset = () => {
                if (context) {
                    context.clearRect(0, 0, canvas.width, canvas.height);
                    context.lineWidth = 2.5; context.lineCap = 'round'; context.lineJoin = 'round'; context.strokeStyle = '#1d4477';
                }
                ink = false; pointer = null; error.textContent = ''; imageInput.value = retainedImage;
                dirty();
            };
            if (context) {
                if (drawChoice) { drawChoice.hidden = false; drawChoice.querySelector('input').disabled = false; }
                if (form.dataset.dwfSignatureNew === '1' && drawChoice) drawChoice.querySelector('input').checked = true;
                const position = event => {
                    const rect = canvas.getBoundingClientRect();
                    return {x:(event.clientX-rect.left)*canvas.width/rect.width,y:(event.clientY-rect.top)*canvas.height/rect.height};
                };
                canvas.addEventListener('pointerdown', event => {
                    if (pointer !== null || (event.pointerType === 'mouse' && event.button !== 0)) return;
                    event.preventDefault(); pointer = event.pointerId; canvas.setPointerCapture(pointer);
                    const point = position(event); context.beginPath(); context.moveTo(point.x,point.y);
                });
                canvas.addEventListener('pointermove', event => {
                    if (pointer !== event.pointerId) return;
                    event.preventDefault(); const point = position(event); context.lineTo(point.x,point.y); context.stroke();
                    ink = true; error.textContent = ''; if (status) status.textContent = ''; dirty();
                });
                const end = event => {if(pointer === event.pointerId){pointer=null;context.closePath();}};
                canvas.addEventListener('pointerup',end); canvas.addEventListener('pointercancel',end); canvas.addEventListener('lostpointercapture',end);
            }
            form.querySelector('[data-dwf-signature-clear]')?.addEventListener('click',() => {
                reset(); if (status) status.textContent = creationText('Unterschrift gelöscht.', form); canvas?.focus();
            });
            radios.forEach(input => input.addEventListener('change',sync));
            file?.addEventListener('change',dirty); accepted?.addEventListener('change',dirty);
            form.addEventListener('submit', event => {
                if (method() === 'draw') {
                    if (!ink) { event.preventDefault(); error.textContent = creationText('Bitte mit Finger oder Maus unterschreiben.', form); canvas?.focus(); dirty(); return; }
                    const image = canvas.toDataURL('image/png');
                    if (image.length > 22000) { event.preventDefault(); error.textContent = creationText('Bitte die Unterschrift neu schreiben.', form); dirty(); return; }
                    imageInput.value = image;
                } else if (method() === 'retained') imageInput.value = retainedImage;
                if (!event.defaultPrevented && (form.noValidate || form.checkValidity())) delete form.dataset.dwfDirty;
            });
            reset(); sync();
        });
        root.querySelectorAll('[data-dwf-signature-all]').forEach(button => {
            if (button.dataset.dwfReady) return;
            button.dataset.dwfReady='1'; button.hidden=false;
            button.addEventListener('click',()=>{
                const boxes=[...button.closest('form').querySelectorAll('input[name="wf_recipients[]"]')];
                const select=boxes.some(box=>!box.checked);
                boxes.forEach(box=>{box.checked=select;box.dispatchEvent(new Event('change',{bubbles:true}));});
                button.textContent=creationText(select?'Auswahl auf dieser Seite aufheben':'Alle auf dieser Seite auswählen',button);
            });
        });
    }
    const init = (root = document) => {
        initSignatures(root);
        editors(root).forEach(refresh);
        root.querySelectorAll('[data-dwf-matrix]').forEach(refreshMatrix);
        root.querySelectorAll('[data-dwf-register]').forEach(refreshRegister);
        root.querySelectorAll('.dwf-document-form').forEach(renderPreview);
        root.querySelectorAll('[data-dwf-post-action]').forEach(workspace => {
            const action = workspace.dataset.dwfPostAction;
            if (!['document-workflow-template-save', 'document-workflow-document-save', 'document-workflow-folder-save', 'document-workflow-document-create', 'document-workflow-signature-request'].includes(action)) return;
            const form = [...workspace.querySelectorAll('form')].find(candidate => candidate.elements.action?.value === action && (candidate.elements.wf_id?.value || '') === (workspace.dataset.dwfPostId || ''));
            if (form) form.dataset.dwfDirty = '1';
            // Do not mark a successfully resubmitted form dirty on a repeated lifecycle mount.
            workspace.dataset.dwfPostAction = '';
        });
    };
    window.KulturhusDocumentWorkflows = {init};

    document.addEventListener('click', event => {
        if (!(event.target instanceof Element)) return;
        const registerButton = event.target.closest('[data-dwf-register-add], [data-dwf-register-remove], [data-dwf-register-move]');
        if (registerButton && !registerButton.disabled) {
            event.preventDefault();
            const register = registerButton.closest('[data-dwf-register]'), list = register.querySelector('[data-dwf-register-rows]'), form = register.closest('form');
            let message = '';
            if (registerButton.hasAttribute('data-dwf-register-add')) {
                if (registerRows(register).length >= 30) return;
                const fragment = register.querySelector('[data-dwf-register-row-template]').content.cloneNode(true), row = fragment.querySelector('[data-dwf-register-row]');
                list.append(fragment); refreshRegister(register); row.querySelector('textarea').focus(); message = 'Gefährdungszeile hinzugefügt. Entwurf bitte speichern.';
            } else {
                const row = registerButton.closest('[data-dwf-register-row]');
                if (registerButton.hasAttribute('data-dwf-register-remove')) {
                    row.remove(); register.querySelector('[data-dwf-register-add]').focus(); message = 'Gefährdungszeile aus dem Arbeitsstand entfernt. Entwurf bitte speichern.';
                } else {
                    const previous = registerButton.dataset.dwfRegisterMove === 'up';
                    const sibling = previous ? row.previousElementSibling : row.nextElementSibling;
                    if (!sibling) return;
                    if (previous) list.insertBefore(row, sibling); else list.insertBefore(sibling, row);
                    row.querySelector('textarea').focus(); message = 'Zeilenreihenfolge geändert. Entwurf bitte speichern.';
                }
                refreshRegister(register);
            }
            register.querySelector('[data-dwf-register-status]').textContent = creationText(message,register);
            changed(form);
            return;
        }
        const matrixCell = event.target.closest('[data-dwf-risk-likelihood]');
        if (matrixCell && !matrixCell.disabled) {
            const matrix = matrixCell.closest('[data-dwf-matrix]');
            const likelihood = matrix.querySelector('[data-dwf-axis="likelihood"]');
            const severity = matrix.querySelector('[data-dwf-axis="severity"]');
            likelihood.value = matrixCell.dataset.dwfRiskLikelihood;
            severity.value = matrixCell.dataset.dwfRiskSeverity;
            likelihood.dispatchEvent(new Event('input', {bubbles: true}));
            severity.dispatchEvent(new Event('change', {bubbles: true}));
            return;
        }
        const button = event.target.closest('[data-dwf-add], [data-dwf-remove], [data-dwf-move]');
        const editor = button?.closest('[data-dwf-template-editor]');
        if (!editor || button.disabled) return;
        event.preventDefault();
        const list = editor.querySelector('[data-dwf-fields]');
        if (button.hasAttribute('data-dwf-add')) {
            if (rows(editor).length >= 100) return;
            const fragment = editor.querySelector('[data-dwf-field-template]').content.cloneNode(true);
            const row = fragment.querySelector('[data-dwf-field]');
            const keys = new Set(rows(editor).map(item => item.querySelector('[data-dwf-key]').value));
            let number = rows(editor).length + 1;
            while (keys.has(`feld_${number}`)) number++;
            row.querySelector('[data-dwf-key]').value = `feld_${number}`;
            list.append(fragment);
            refresh(editor);
            row.querySelector('[data-dwf-label]').focus();
            announce(editor, 'Formularfeld hinzugefügt. Änderungen bitte speichern.');
        } else {
            const row = button.closest('[data-dwf-field]');
            if (button.hasAttribute('data-dwf-remove')) {
                const next = row.nextElementSibling || row.previousElementSibling;
                row.remove();
                refresh(editor);
                (next?.querySelector('[data-dwf-label]') || editor.querySelector('[data-dwf-add]')).focus();
                announce(editor, 'Formularfeld aus dem Arbeitsstand entfernt. Änderungen bitte speichern.');
            } else {
                const sibling = button.dataset.dwfMove === 'up' ? row.previousElementSibling : row.nextElementSibling;
                if (!sibling) return;
                if (button.dataset.dwfMove === 'up') list.insertBefore(row, sibling);
                else list.insertBefore(sibling, row);
                refresh(editor);
                row.querySelector('[data-dwf-label]').focus();
                announce(editor, 'Feldreihenfolge geändert. Änderungen bitte speichern.');
            }
        }
        changed(editor);
    });
    document.addEventListener('change', event => {
        if (!(event.target instanceof Element) || !event.target.matches('[data-dwf-type]')) return;
        const editor = event.target.closest('[data-dwf-template-editor]');
        if (editor) refresh(editor);
    });
    const markDirty = event => {
        if (!(event.target instanceof Element)) return;
        const form = event.target.closest('[data-dwf-template-editor], .dwf-document-form, [data-dwf-create-form], [data-dwf-edit-form]');
        if (form) {
            form.dataset.dwfDirty = '1';
            const matrix = event.target.closest('[data-dwf-matrix]');
            if (matrix) refreshMatrix(matrix);
            const register = event.target.closest('[data-dwf-register]');
            if (register) refreshRegister(register);
            renderPreview(form);
        }
    };
    document.addEventListener('input', markDirty);
    document.addEventListener('change', markDirty);
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const workspace = form.closest('[data-dwf-workspace]') || (form.closest('.dwf-demo-header') ? document.querySelector('[data-dwf-workspace]') : null);
        if (!workspace) return;
        const otherDirty = dirtyForms(workspace).find(dirty => dirty !== form);
        if (otherDirty) {
            event.preventDefault();
            event.stopImmediatePropagation();
            let message = workspace.querySelector('[data-dwf-unsaved-warning]');
            if (!message) {
                message = document.createElement('p');
                message.className = 'dwf-alert dwf-error';
                message.dataset.dwfUnsavedWarning = '1';
                message.setAttribute('role', 'alert');
                form.prepend(message);
            }
            message.textContent = otherDirty.matches('[data-dwf-signature-request-form]') ? creationText('Bitte zuerst die Personenauswahl anfordern oder bewusst verlassen.',workspace) : otherDirty.matches('[data-dwf-template-editor]')
                ? creationText('Bitte zuerst die Änderungen an der Vorlage speichern. Danach den gespeicherten Stand veröffentlichen.', workspace)
                : otherDirty.matches('.dwf-folder-edit') ? creationText('Bitte zuerst den Ordner speichern oder seine Bearbeitung bewusst verlassen.', workspace)
                : creationText('Bitte zuerst den Entwurf speichern. Danach Zuweisung oder Status ändern.', workspace);
            message.scrollIntoView({block: 'center', behavior: 'smooth'});
            return;
        }
        if (!event.defaultPrevented && (form.noValidate || form.checkValidity())) delete form.dataset.dwfDirty;
    }, true);
    window.addEventListener('beforeunload', event => {
        if (!dirtyForms(document).length) return;
        event.preventDefault();
        event.returnValue = '';
    });
    document.addEventListener('DOMContentLoaded', () => init(document));
    document.addEventListener('editorial:navigated', () => init(document));
    document.addEventListener('manager:mounted', event => init(event.detail?.root || document));
    if (window.KulturhusPage?.register) window.KulturhusPage.register('document-workflows', ({root, guard}) => {
        init(root);
        guard(() => dirtyForms(root).length > 0);
    });
    init(document);
})();
