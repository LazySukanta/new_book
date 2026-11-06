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
