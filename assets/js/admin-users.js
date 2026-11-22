document.addEventListener('DOMContentLoaded', () => {
  const editForm = document.getElementById('admin-edit-form');
  const deleteForm = document.getElementById('admin-delete-id');
  const deleteName = document.getElementById('admin-delete-name');

  document.querySelectorAll('[data-user-id]').forEach((button) => {
    button.addEventListener('click', () => {
      if (!editForm) return;
      editForm.elements.id.value = button.dataset.userId || '';
      editForm.elements.name.value = button.dataset.userName || '';
      editForm.elements.email.value = button.dataset.userEmail || '';
      editForm.elements.role.value = button.dataset.userRole || 'user';
      editForm.elements.password.value = '';
    });
  });

  document.querySelectorAll('[data-delete-id]').forEach((button) => {
    button.addEventListener('click', () => {
      if (deleteForm) deleteForm.value = button.dataset.deleteId || '';
      if (deleteName) deleteName.textContent = button.dataset.deleteName || '';
    });
  });
});
