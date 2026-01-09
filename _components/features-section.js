class FeaturesSection extends HTMLElement {
  connectedCallback() {
    if (this._rendered) return;
    this._rendered = true;

    // Move existing children into a grid wrapper
    const grid = document.createElement('div');
    grid.className = 'grid';

    // Move all current children into the grid
    while (this.firstChild) {
      grid.appendChild(this.firstChild);
    }

    // Optional: inject styles
    const style = document.createElement('style');
    style.textContent = `
      features-section {
        display:block;
        padding: 4rem 2rem;
        background: #f7f7f7;
      }
      .grid {
        max-width: 1100px;
        margin:0 auto;
        display:grid;
        grid-template-columns: repeat(auto-fit, minmax(250px,1fr));
        gap:2rem; }
    `;

    this.appendChild(style);
    this.appendChild(grid);
  }
}
customElements.define('features-section', FeaturesSection);