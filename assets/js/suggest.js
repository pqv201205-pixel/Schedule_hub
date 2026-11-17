(() => {
  const subject = document.querySelector('#subject');
  const day = document.querySelector('#day');
  const shift = document.querySelector('#shift');
  const output = document.querySelector('#suggestedTeachers');
  const teacherSelect = document.querySelector('#teacher');
  const id = document.querySelector('#scheduleId');
  if (!subject || !day || !shift || !output) return;
  let timer;
  let controller;
  const refresh = async () => {
    if (!subject.value.trim() || !day.value || !shift.value) {
      output.replaceChildren(); return;
    }
    if (controller) controller.abort();
    controller = new AbortController();
    const query = new URLSearchParams({page:'suggest', subject:subject.value, day:day.value, shift:shift.value, exclude:id.value});
    try {
      const response = await fetch('index.php?' + query, {signal:controller.signal, credentials:'same-origin'});
      if (!response.ok) throw new Error('Không thể tải dữ liệu gợi ý');
      const payload = await response.json();
      output.replaceChildren();
      const msg = document.createElement('p');
      msg.textContent = payload.message;
      output.append(msg);
      const group = document.createElement('div');
      group.className = 'suggest-list';
      payload.teachers.forEach(teacher => {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'suggest-item';
        b.textContent = '✓ ' + teacher.name + ' · ' + teacher.expertise;
        b.addEventListener('click', () => { teacherSelect.value = String(teacher.id); teacherSelect.focus(); });
        group.append(b);
      });
      output.append(group);
    } catch (err) {
      if (err.name !== 'AbortError') output.textContent = 'Không thể tải gợi ý lúc này.';
    }
  };
  [subject,day,shift].forEach(node => node.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(refresh, 220); }));
  refresh();
})();
