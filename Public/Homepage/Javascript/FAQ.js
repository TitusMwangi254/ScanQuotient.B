
        document.addEventListener('DOMContentLoaded', () => {
            const faqItems = document.querySelectorAll('.faq-item');

            faqItems.forEach(item => {
                const question = item.querySelector('.faq-question');
                const answer = item.querySelector('.faq-answer');

                question.addEventListener('click', () => {
                    // Close all other open answers
                    faqItems.forEach(otherItem => {
                        if (otherItem !== item) {
                            otherItem.querySelector('.faq-question').classList.remove('active');
                            otherItem.querySelector('.faq-answer').classList.remove('show');
                        }
                    });

                    // Toggle the clicked item's answer
                    question.classList.toggle('active');
                    answer.classList.toggle('show');
                });
            });

            // Dynamically create and append the back-to-top button
            const backToTopButton = document.createElement('div');
            backToTopButton.id = 'back-to-top';
            backToTopButton.innerHTML = '<i class="fas fa-arrow-up"></i>';
            backToTopButton.onclick = () => window.scrollTo({top: 0, behavior: 'smooth'});
            document.body.appendChild(backToTopButton);

            // Back to Top Button Visibility on scroll
            window.addEventListener('scroll', () => {
                if (window.scrollY > 300) {
                    backToTopButton.style.display = 'flex'; /* Use flex to center icon */
                } else {
                    backToTopButton.style.display = 'none';
                }
            });
            backToTopButton.style.display = 'none'; // Initially hidden

            // --- Theme Toggle Functionality ---
            const themeToggleBtn = document.getElementById('theme-toggle');
            const body = document.body;
            const themeIcon = themeToggleBtn.querySelector('i');

            // Load theme preference from localStorage or default to light
            const currentTheme = localStorage.getItem('theme');
            if (currentTheme === 'dark-theme') {
                body.classList.add('dark-theme');
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
            } else {
                // Ensure light theme is active by default if no preference is set or it's 'light-theme'
                body.classList.remove('dark-theme');
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
            }

            themeToggleBtn.addEventListener('click', () => {
                body.classList.toggle('dark-theme');
                if (body.classList.contains('dark-theme')) {
                    localStorage.setItem('theme', 'dark-theme');
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                } else {
                    localStorage.setItem('theme', 'light-theme');
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                }
            });
        });
    