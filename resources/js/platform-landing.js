function initializeLandingStories() {
    document.querySelectorAll('[data-landing-story]').forEach((story) => {
        if (story.dataset.initialized === 'true') return;
        story.dataset.initialized = 'true';
        const steps = Array.from(story.querySelectorAll('[data-landing-story-step]'));
        const images = Array.from(story.querySelectorAll('[data-landing-story-image]'));
        const copies = Array.from(story.querySelectorAll('[data-landing-story-copy]'));
        const stage = story.querySelector('[data-landing-story-stage]');
        const counter = story.querySelector('[data-landing-story-counter]');
        const dialog = story.querySelector('[data-landing-preview-dialog]');
        const expandButton = story.querySelector('[data-landing-story-expand]');
        const zoomButton = dialog?.querySelector('[data-landing-preview-zoom]');
        const previewViewport = dialog?.querySelector('[data-landing-preview-viewport]');
        const setZoom = (zoomed) => {
            if (!zoomButton || !previewViewport) return;
            previewViewport.classList.toggle('is-zoomed', zoomed);
            previewViewport.tabIndex = zoomed ? 0 : -1;
            zoomButton.setAttribute('aria-pressed', String(zoomed));
            zoomButton.textContent = zoomed ? zoomButton.dataset.fitLabel : zoomButton.dataset.zoomLabel;
            previewViewport.scrollTo(0, 0);
        };
        const synchronizePreview = () => {
            const sourceImage = images[activeIndex]?.querySelector('img');
            const sourceCopy = copies[activeIndex];
            if (!dialog || !sourceImage || !sourceCopy) return;
            setZoom(false);
            const previewImage = dialog.querySelector('[data-landing-preview-image]');
            previewImage.src = sourceImage.src;
            previewImage.alt = sourceImage.alt;
            dialog.querySelector('[data-landing-preview-title]').textContent = sourceCopy.querySelector('h3').textContent;
            dialog.querySelector('[data-landing-preview-description]').textContent = sourceCopy.querySelector('p').textContent;
            dialog.querySelector('[data-landing-preview-counter]').textContent = `${activeIndex + 1} / ${steps.length}`;
        };
        let activeIndex = 0;
        const activate = (index) => {
            activeIndex = Number(index);
            steps.forEach((step) => {
                const active = step.dataset.landingStoryStep === index;
                step.classList.toggle('is-active', active);
                step.setAttribute('aria-selected', active ? 'true' : 'false');
                step.tabIndex = active ? 0 : -1;
            });
            images.forEach((image) => {
                const active = image.dataset.landingStoryImage === index;
                image.classList.toggle('is-active', active);
                image.setAttribute('aria-hidden', active ? 'false' : 'true');
                image.tabIndex = active ? 0 : -1;
            });
            copies.forEach((copy) => {
                const active = copy.dataset.landingStoryCopy === index;
                copy.classList.toggle('is-active', active);
                copy.setAttribute('aria-hidden', active ? 'false' : 'true');
            });
            const position = Number(index) + 1;
            stage?.style.setProperty('--story-progress', `${(position / steps.length) * 100}%`);
            if (counter) {
                counter.textContent = `${String(position).padStart(2, '0')} / ${String(steps.length).padStart(2, '0')}`;
            }
            if (dialog?.open) synchronizePreview();
        };
        steps.forEach((step) => {
            ['click', 'focus'].forEach((eventName) => {
                step.addEventListener(eventName, () => activate(step.dataset.landingStoryStep));
            });

            step.addEventListener('keydown', (event) => {
                const currentIndex = steps.indexOf(step);
                const rtl = document.documentElement.dir === 'rtl';
                const previousKey = rtl ? 'ArrowRight' : 'ArrowLeft';
                const nextKey = rtl ? 'ArrowLeft' : 'ArrowRight';
                let targetIndex = null;

                if (event.key === previousKey || event.key === 'ArrowUp') {
                    targetIndex = (currentIndex - 1 + steps.length) % steps.length;
                } else if (event.key === nextKey || event.key === 'ArrowDown') {
                    targetIndex = (currentIndex + 1) % steps.length;
                } else if (event.key === 'Home') {
                    targetIndex = 0;
                } else if (event.key === 'End') {
                    targetIndex = steps.length - 1;
                }

                if (targetIndex === null) return;

                event.preventDefault();
                activate(steps[targetIndex].dataset.landingStoryStep);
                steps[targetIndex].focus();
            });
        });
        const move = (direction) => {
            if (!steps.length) return;
            const target = (activeIndex + direction + steps.length) % steps.length;
            activate(steps[target].dataset.landingStoryStep);
            if (!dialog?.open) steps[target].scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'instant' });
        };
        story.querySelector('[data-landing-story-previous]')?.addEventListener('click', () => move(-1));
        story.querySelector('[data-landing-story-next]')?.addEventListener('click', () => move(1));
        expandButton?.addEventListener('click', () => {
            if (!dialog || typeof dialog.showModal !== 'function') return;
            synchronizePreview();
            dialog.showModal();
            document.body.classList.add('landing-preview-open');
        });
        dialog?.querySelector('[data-landing-preview-close]')?.addEventListener('click', () => dialog.close());
        dialog?.querySelector('[data-landing-preview-previous]')?.addEventListener('click', () => move(-1));
        dialog?.querySelector('[data-landing-preview-next]')?.addEventListener('click', () => move(1));
        zoomButton?.addEventListener('click', () => setZoom(zoomButton.getAttribute('aria-pressed') !== 'true'));
        dialog?.addEventListener('close', () => {
            document.body.classList.remove('landing-preview-open');
            expandButton?.focus({ preventScroll: true });
        });
        dialog?.addEventListener('keydown', (event) => {
            if (event.target === previewViewport && previewViewport.classList.contains('is-zoomed')) return;
            const rtl = document.documentElement.dir === 'rtl';
            if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                event.preventDefault();
                move(event.key === (rtl ? 'ArrowLeft' : 'ArrowRight') ? 1 : -1);
            }
        });
    });

    document.querySelectorAll('.landing-mobile-nav').forEach((menu) => {
        if (menu.dataset.initialized === 'true') return;
        menu.dataset.initialized = 'true';
        menu.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => menu.removeAttribute('open'));
        });
        menu.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || !menu.open) return;
            event.preventDefault();
            menu.removeAttribute('open');
            menu.querySelector('summary')?.focus();
        });
        document.addEventListener('click', (event) => {
            if (menu.open && !menu.contains(event.target)) menu.removeAttribute('open');
        });
    });
}

function initializeLandingPackages() {
    document.querySelectorAll('[data-landing-packages]').forEach((explorer) => {
        if (explorer.dataset.initialized === 'true') return;
        explorer.dataset.initialized = 'true';

        const tabs = Array.from(explorer.querySelectorAll('[data-landing-package-tab]'));
        const panels = Array.from(explorer.querySelectorAll('[data-landing-package-panel]'));
        const activate = (index) => {
            tabs.forEach((tab) => {
                const active = tab.dataset.landingPackageTab === index;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
                tab.tabIndex = active ? 0 : -1;
            });
            panels.forEach((panel) => {
                const active = panel.dataset.landingPackagePanel === index;
                panel.classList.toggle('is-active', active);
                panel.setAttribute('aria-hidden', active ? 'false' : 'true');
                panel.tabIndex = active ? 0 : -1;

                if (!active) {
                    panel.querySelectorAll('details[open]').forEach((details) => details.removeAttribute('open'));
                }
            });
        };

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => activate(tab.dataset.landingPackageTab));
            tab.addEventListener('keydown', (event) => {
                const currentIndex = tabs.indexOf(tab);
                const rtl = document.documentElement.dir === 'rtl';
                const previousKey = rtl ? 'ArrowRight' : 'ArrowLeft';
                const nextKey = rtl ? 'ArrowLeft' : 'ArrowRight';
                let targetIndex = null;

                if (event.key === previousKey || event.key === 'ArrowUp') {
                    targetIndex = (currentIndex - 1 + tabs.length) % tabs.length;
                } else if (event.key === nextKey || event.key === 'ArrowDown') {
                    targetIndex = (currentIndex + 1) % tabs.length;
                } else if (event.key === 'Home') {
                    targetIndex = 0;
                } else if (event.key === 'End') {
                    targetIndex = tabs.length - 1;
                }

                if (targetIndex === null) return;

                event.preventDefault();
                activate(tabs[targetIndex].dataset.landingPackageTab);
                tabs[targetIndex].focus();
            });
        });
    });
}

function initializeLandingFaqs() {
    document.querySelectorAll('[data-landing-faq]').forEach((faq) => {
        if (faq.dataset.initialized === 'true') return;
        faq.dataset.initialized = 'true';

        const items = Array.from(faq.querySelectorAll('details'));
        items.forEach((item) => {
            item.addEventListener('toggle', () => {
                if (!item.open) return;
                items.forEach((otherItem) => {
                    if (otherItem !== item) otherItem.removeAttribute('open');
                });
            });
        });
    });
}

document.addEventListener('DOMContentLoaded', initializeLandingStories);
document.addEventListener('livewire:navigated', initializeLandingStories);
document.addEventListener('DOMContentLoaded', initializeLandingPackages);
document.addEventListener('livewire:navigated', initializeLandingPackages);
document.addEventListener('DOMContentLoaded', initializeLandingFaqs);
document.addEventListener('livewire:navigated', initializeLandingFaqs);

function initializeLandingNavigation() {
    const navigation = document.querySelector('.landing-nav');
    if (!navigation || navigation.dataset.initialized === 'true' || !('IntersectionObserver' in window)) return;
    navigation.dataset.initialized = 'true';

    const links = Array.from(navigation.querySelectorAll('a[href^="#"]'));
    const sections = links.map((link) => document.getElementById(link.hash.slice(1))).filter(Boolean);
    const visible = new Set();
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) visible.add(entry.target);
            else visible.delete(entry.target);
        });
        const current = sections.find((section) => visible.has(section));
        links.forEach((link) => {
            if (current && link.hash === `#${current.id}`) link.setAttribute('aria-current', 'location');
            else link.removeAttribute('aria-current');
        });
    }, { rootMargin: '-15% 0px -65% 0px' });

    sections.forEach((section) => observer.observe(section));
}

document.addEventListener('DOMContentLoaded', initializeLandingNavigation);
document.addEventListener('livewire:navigated', initializeLandingNavigation);
