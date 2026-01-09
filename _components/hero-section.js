import { BaseComponent } from './base-component.js';

class HeroSection extends BaseComponent {
  constructor() {
    super();
    this.wrapperTag = 'div';
    this.wrapperClass = 'hero inner';

    this.styles = `
      hero-section { display:block; background:#333; color:#fff; }
      .hero { position:relative; text-align:center; padding:4rem 2rem; }
      .hero img { max-width:100%; height:auto; margin-bottom:2rem; display:block; margin-left:auto; margin-right:auto; }
      .hero h1 { font-size:2.5rem; margin-bottom:1rem; }
      .hero p { font-size:1.25rem; color:#ddd; }
      .inner { max-width:1100px; margin:0 auto; display:flex; align-items:center; justify-content:space-between; gap:4rem; }
    `;
  }

  // container is the wrapper div created by BaseComponent
  render(container) {
    const title = this.getAttribute('title') || 'Default Title';
    const subtitle = this.getAttribute('subtitle') || 'Default subtitle';
    const image = this.getAttribute('image') || '';

    container.insertAdjacentHTML('beforeend', `
      <div class="hero-text">
        <h1>${title}</h1>
        <p>${subtitle}</p>
      </div>
      <div class="hero-img">
        ${image ? `<img src="/_assets/img/${image}" alt="${title}">` : ''}
      </div>
    `);
  }
}

customElements.define('hero-section', HeroSection);