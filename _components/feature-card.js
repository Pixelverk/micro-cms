class FeatureCard extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
  }

  connectedCallback() {
    if (this._rendered) return;
    this._rendered = true;

    const title = this.getAttribute('title') || 'Feature Title';
    const text = this.getAttribute('text') || 'Feature description.';
    const icon = this.getAttribute('icon') || '';
    const image = this.getAttribute('image') || '';

    this.shadowRoot.innerHTML = `
      <style>
        .card {
          background: #fff;
          border-radius: 8px;
          padding: 2rem 1rem;
          box-shadow: 0 2px 6px rgba(0,0,0,0.1);
          text-align: center;
        }

        .card img {
          max-width: 100px;
          height: auto;
          margin-bottom: 1rem;
        }

        .card h2 {
          font-size: 1.5rem;
          margin-bottom: 0.5rem;
        }

        .card p {
          font-size: 1rem;
          color: #555;
        }

        .card .icon {
          font-size: 2rem;
          margin-bottom: 1rem;
        }
      </style>

      <div class="card">
        ${image ? `<img src="./img/${image}" alt="${title}">` : ''}
        ${icon ? `<div class="icon">${icon}</div>` : ''}
        <h2>${title}</h2>
        <p>${text}</p>
        <slot></slot>
      </div>
    `;
  }
}

customElements.define('feature-card', FeatureCard);
