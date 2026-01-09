import { BaseComponent } from './base-component.js';

class FeaturesSection extends BaseComponent {
  constructor() {
    super();
    // Wrapper for child cards
    this.wrapperTag = 'div';
    this.wrapperClass = 'grid inner';

    this.styles = `
      features-section { display:block; padding:4rem 2rem; background:#f7f7f7; }
      .grid { 
        display:grid; 
        grid-template-columns:repeat(auto-fit, minmax(250px,1fr)); 
        gap:2rem; 
      }
    `;
  }

  // container is the wrapper div created by BaseComponent
  render(container) {
    // Nothing else needed; children from light DOM are already moved inside .grid wrapper
    // You could add extra content here if needed
  }
}

customElements.define('features-section', FeaturesSection);