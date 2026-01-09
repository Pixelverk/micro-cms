import { BaseComponent } from './base-component.js';

class FeatureCard extends BaseComponent {
  constructor() {
    super();
    // Wrapper div for the card content
    this.wrapperTag = 'div';
    this.wrapperClass = 'card';

    this.styles = `
      feature-card { display:block; }
      .card { 
        background:#fff; 
        border-radius:8px; 
        padding:2rem 1rem; 
        box-shadow:0 2px 6px rgba(0,0,0,0.1); 
        text-align:center; 
      }
      .card img { max-width:100px; height:auto; margin-bottom:1rem; }
      .card h2 { font-size:1.5rem; margin-bottom:0.5rem; }
      .card p { font-size:1rem; color:#555; }
      .card .icon { font-size:2rem; margin-bottom:1rem; }
    `;
  }

  // container is the wrapper div created by BaseComponent
  render(container) {
    const title = this.getAttribute('title') || 'Feature Title';
    const text = this.getAttribute('text') || 'Feature description.';
    const icon = this.getAttribute('icon') || '';
    const image = this.getAttribute('image') || '';

    container.insertAdjacentHTML('beforeend', `
      ${image ? `<img src="/_assets/img/${image}" alt="${title}">` : ''}
      ${icon ? `<div class="icon">${icon}</div>` : ''}
      <h2>${title}</h2>
      <p>${text}</p>
    `);
  }
}

customElements.define('feature-card', FeatureCard);