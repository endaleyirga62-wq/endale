// SDSS Interactivity and Utilities Script

document.addEventListener('DOMContentLoaded', function () {

    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        const roleField = document.getElementById('role');
        const usernameField = document.getElementById('username');
        const serverNotice = document.getElementById('loginServerNotice');

        if (roleField.value && !usernameField.value) {
            usernameField.value = roleField.options[roleField.selectedIndex].dataset.username || '';
        }

        roleField.addEventListener('change', function () {
            const selectedRole = roleField.options[roleField.selectedIndex];
            usernameField.value = selectedRole.dataset.username || '';
        });

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