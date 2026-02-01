// ----------------------------
// Picture + LQIP Handler
// ----------------------------
document.addEventListener("DOMContentLoaded", () => {

    document.querySelectorAll('.image-wrapper picture img').forEach(img => {

        const wrapper = img.closest('.image-wrapper');
        if (!wrapper) return;

        // Remove LQIP blur when image loads
        const removeBlur = () => {
            wrapper.style.filter = 'none';
            wrapper.style.backgroundImage = 'none';
            img.classList.add('loaded');
        };

        if (!img.complete) {
            img.addEventListener('load', removeBlur);
        } else {
            removeBlur();
        }

        // Adjust sizes to actual container width after page load
        const resizeObserver = new ResizeObserver(entries => {
            for (let entry of entries) {
                const w = Math.ceil(entry.contentRect.width);
                if (w > 0) {
                    img.sizes = w + 'px';
                }
            }
        });

        resizeObserver.observe(wrapper);
    });
});