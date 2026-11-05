const applicationError = document.querySelector('.application-form-state [role="alert"]');
if (applicationError) applicationError.focus();

document.querySelectorAll('.kh-membership-form').forEach(form => {
  const type = form.querySelector('[name="membership_type"]');
  const family = form.querySelector('[data-family-fields]');
  if (!type || !family) return;
  const update = () => {
    const visible = type.value !== 'single';
    family.hidden = !visible;
    if (visible && family instanceof HTMLDetailsElement) family.open = true;
    family.querySelectorAll('input,textarea').forEach(field => { field.disabled = !visible; });
    form.querySelector('[data-self-payment]').hidden = type.value === 'family_member';
    form.querySelector('[data-family-payment]').hidden = type.value !== 'family_member';
    const reference = family.querySelector('[data-family-reference]');
    const payerMembers = family.querySelector('[data-family-payer-members]');
    if (payerMembers) {
      payerMembers.hidden = type.value !== 'family';
      payerMembers.querySelector('textarea').disabled = type.value !== 'family';
    }
    if (reference) {
      const existing = type.value === 'family_member';
      reference.hidden = !existing;
      reference.querySelector('input').disabled = !existing;
      reference.querySelector('input').required = existing;
    }
  };
  type.addEventListener('change', update);
  update();
});
