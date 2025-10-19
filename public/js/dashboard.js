document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('startForm');
    const stopBtn = document.getElementById('stopBtn');
    const progressBar = document.getElementById('progress');
    const percentLabel = document.getElementById('percent');
    const errorLabel = document.getElementById('errorCount');
    const lastLabel = document.getElementById('lastRecord');
    const jobStatus = document.getElementById('jobStatus');
    const logBox = document.getElementById('logBox');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = new FormData(form);
        await fetch('/process/start', { method: 'POST', body: data });
    });

    stopBtn.addEventListener('click', async () => {
        await fetch('/process/stop');
    });

    async function refreshStatus() {
        const res = await fetch('/process/status');
        const data = await res.json();

        jobStatus.textContent = data.job?.status ?? 'idle';
        progressBar.value = data.progress || 0;
        percentLabel.textContent = (data.progress || 0) + '%';
        errorLabel.textContent = data.errors || 0;
        lastLabel.textContent = data.lastRecord ?? '-';

        logBox.textContent = data.log || '';
        logBox.scrollTop = logBox.scrollHeight;
    }

    setInterval(refreshStatus, 2000);
});
