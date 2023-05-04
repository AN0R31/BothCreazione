const panels = document.querySelectorAll('.panel');

function removeActiveClasses() {
    for (let panel of panels) {
        panel.classList.remove('active_pic');
    }
}

for (let panel of panels) {
    panel.addEventListener('click', () => {
        removeActiveClasses();
        panel.classList.add('active_pic');
    })
}