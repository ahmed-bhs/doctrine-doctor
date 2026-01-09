// Custom JavaScript for Doctrine Doctor documentation

document.addEventListener('DOMContentLoaded', function() {
    // Add copy button functionality enhancement
    const codeBlocks = document.querySelectorAll('pre code');
    codeBlocks.forEach(block => {
        block.parentElement.classList.add('highlight');
    });

    // Add external link indicators
    const links = document.querySelectorAll('a[href^="http"]');
    links.forEach(link => {
        if (!link.hostname.includes('ahmed-bhs.github.io')) {
            link.setAttribute('target', '_blank');
            link.setAttribute('rel', 'noopener noreferrer');
        }
    });

    // Smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
});
