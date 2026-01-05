document.addEventListener('DOMContentLoaded', function() {
    const typeaheadInput = document.getElementById('typeahead-input');
    const suggestionsList = document.getElementById('suggestions-list');
    const iconPickerButton = document.getElementById('icon-picker-button');
    const iconPickerModal = document.getElementById('icon-picker-modal');
    const selectedIcon = document.getElementById('selected-icon');

    // Typeahead functionality
    typeaheadInput.addEventListener('input', function() {
        const query = this.value;

        if (query.length > 2) {
            fetch(`/api/typeahead.php?query=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    suggestionsList.innerHTML = '';
                    data.suggestions.forEach(suggestion => {
                        const li = document.createElement('li');
                        li.textContent = suggestion;
                        li.addEventListener('click', function() {
                            typeaheadInput.value = suggestion;
                            suggestionsList.innerHTML = '';
                        });
                        suggestionsList.appendChild(li);
                    });
                });
        } else {
            suggestionsList.innerHTML = '';
        }
    });

    // Icon picker functionality
    iconPickerButton.addEventListener('click', function() {
        iconPickerModal.classList.toggle('is-active');
    });

    const iconElements = document.querySelectorAll('.icon-option');
    iconElements.forEach(icon => {
        icon.addEventListener('click', function() {
            const iconClass = this.dataset.icon;
            selectedIcon.className = `icon ${iconClass}`;
            iconPickerModal.classList.remove('is-active');
        });
    });
});