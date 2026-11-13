import './pos';
import './invoice';
import './parties';
import './reports';
import transferDraft from './transfers';

window.transferDraft = transferDraft;

document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-open-dialog]');
    if (opener) {
        document.getElementById(opener.dataset.openDialog)?.showModal();
    }

    const closer = event.target.closest('[data-close-dialog]');
    closer?.closest('dialog')?.close();
});

window.addEventListener('workspace-branch-changed', () => {
    document.getElementById('branch-dialog')?.close();
});
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-add-row], [data-remove-row]');
    const group = button?.closest('[data-repeat-group]');
    if (!group) return;

    const rows = group.querySelector('[data-repeat-rows]');
    if (button.hasAttribute('data-remove-row')) {
        button.closest('[data-repeat-row]').remove();
        group.querySelector('[data-add-row]').focus();
        return;
    }

    if (rows.children.length >= Number(group.dataset.limit)) return;
    const index = group.dataset.next;
    group.dataset.next = String(Number(index) + 1);
    const content = group.querySelector('template').content.cloneNode(true);
    for (const field of content.querySelectorAll('[name], [id], [for]')) {
        for (const attribute of ['name', 'id', 'for']) {
            if (field.hasAttribute(attribute)) {
                field.setAttribute(attribute, field.getAttribute(attribute).replaceAll('__ROW__', index) + (attribute === 'name' ? '' : '-' + index));
            }
        }
    }
    const firstField = content.querySelector('input, select');
    rows.append(content);
    firstField?.focus();
});
