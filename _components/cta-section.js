import { BaseComponent } from './base-component.js';

class CTASection extends BaseComponent {
  constructor() {
    super();
    // Wrapper div for CTA content
    this.wrapperTag = 'div';
    this.wrapperClass = 'cta inner';

    this.styles = `
      cta-section { display:block; background:#e0f7fa; }
      .cta { padding:3rem 2rem; text-align:center; display:block; }
      .cta h1 { font-size:2rem; margin-bottom:1rem; }
      .cta p { font-size:1.2rem; margin-bottom:1.5rem; }
      .cta-button { display:inline-block; padding:0.75rem 1.5rem; background:#00796b; color:#fff; text-decoration:none; border-radius:4px; }
    `;
  }

  // container is the wrapper div created by BaseComponent
  render(container) {
    const title = this.getAttribute('title') || 'Default Title';
    const text = this.getAttribute('text') || 'Default text';
    const url = this.getAttribute('url') || '#';
    const linktext = this.getAttribute('linktext') || 'Click here';

    container.insertAdjacentHTML('beforeend', `
      <h1>${title}</h1>
      <p>${text}</p>
      <a href="${url}" class="cta-button">${linktext}</a>
    `);
  }
}

customElements.define('cta-section', CTASection);