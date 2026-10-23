// SDSS Interactivity and Utilities Script

document.addEventListener('DOMContentLoaded', function () {

    const announcementLists = document.querySelectorAll('[data-announcement-list]');
    announcementLists.forEach(function (list) {
        fetch('../../backend/public_announcements.php', { headers: { Accept: 'application/json' } })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Announcements request failed with status ' + response.status);
                }
                return response.json();
            })
            .then(function (announcements) {
                if (!Array.isArray(announcements)) {
                    throw new Error('Announcements response has an invalid format');
                }
                list.replaceChildren();
                const isHomeList = list.dataset.announcementList === 'home';
                const visibleAnnouncements = isHomeList ? announcements.slice(0, 3) : announcements;
                if (visibleAnnouncements.length === 0) {
                    const empty = document.createElement('p');
                    empty.className = 'text-sm text-slate-500';
                    empty.textContent = 'No announcements have been published.';
                    list.appendChild(empty);
                    return;
                }

                visibleAnnouncements.forEach(function (announcement) {
                    const card = document.createElement('article');
                    const title = document.createElement('h3');
                    const body = document.createElement('p');
                    const date = document.createElement('p');
                    title.textContent = announcement.title || 'Announcement';
                    body.textContent = announcement.body || '';
                    date.textContent = announcement.created_at || '';

                    if (isHomeList) {
                        card.className = 'announcement-card rounded-xl border border-slate-200 bg-slate-50 p-5';
                        title.className = 'font-bold text-slate-900 mt-2';
                        body.className = 'text-sm text-slate-600 mt-2';
                        date.className = 'text-xs font-semibold text-indigo-600 uppercase tracking-wide';
                        card.append(date, title, body);
                    } else {
                        card.className = 'bg-white rounded-2xl border border-slate-200 shadow-sm p-6 md:p-8';
                        title.className = 'text-xl font-bold text-slate-900';
                        body.className = 'text-slate-600 mt-3 leading-relaxed';
                        date.className = 'text-xs text-slate-400 mb-3';
                        card.append(date, title, body);
                    }
                    list.appendChild(card);
                });
            })
            .catch(function (error) {
                console.error('Unable to load SDSS announcements:', error);
                const message = document.createElement('p');
                message.className = 'text-sm text-rose-700';
                message.setAttribute('role', 'alert');
                message.textContent = 'Announcements could not be loaded. Check that the PHP server is running and refresh this page.';
                list.replaceChildren(message);
            });
    });

    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        const roleField = document.getElementById('role');
        const usernameField = document.getElementById('username');
        const serverNotice = document.getElementById('loginServerNotice');

        if (roleField && usernameField) {
            if (roleField.value && !usernameField.value) {
                usernameField.value = roleField.options[roleField.selectedIndex].dataset.username || '';
            }

            roleField.addEventListener('change', function () {
                const selectedRole = roleField.options[roleField.selectedIndex];
                usernameField.value = selectedRole.dataset.username || '';
            });
        }

        loginForm.addEventListener('submit', function (event) {
            if (window.location.port === '5500') {
                event.preventDefault();
                if (serverNotice) {
                    serverNotice.classList.remove('hidden');
                    serverNotice.focus();
                }
            }
        });
    }
    
    // 1. Auto-dismiss Alert Messages after 5 seconds
    const alerts = document.querySelectorAll('.alert-fade');
    alerts.forEach(function (alert) {
        setTimeout(function () {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s ease';
            setTimeout(function () {
                alert.remove();
            }, 500);
        }, 5000);
    });

    // 2. Prevent Duplicate Department Selection in Student Choices (Client-side validation)
    const choice1 = document.getElementById('choice1');
    const choice2 = document.getElementById('choice2');
    const choice3 = document.getElementById('choice3');

    if (choice1 && choice2 && choice3) {
        const selects = [choice1, choice2, choice3];

        selects.forEach(select => {
            select.addEventListener('change', function () {
                const selectedValues = selects.map(s => s.value).filter(val => val !== '');
                const hasDuplicates = selectedValues.some((val, i) => selectedValues.indexOf(val) !== i);

                if (hasDuplicates) {
                    alert('እባክዎን የተለያየ ዲፓርትመንት ይምረጡ! አንድን ዲፓርትመንት ከሁለት ጊዜ በላይ መምረጥ አይቻልም።');
                    this.value = '';
                }
            });
        });
    }

});