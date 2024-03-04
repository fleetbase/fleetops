/* eslint-disable no-undef */
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class JointGraphComponent extends Component {
    @tracked graph;
    @tracked paper;
    @tracked height = 400;
    @tracked width = 800;
    @tracked gridSize = 1;
    @tracked fullSize = true;
    @tracked dragStartPosition = {};

    constructor(owner, { height = 400, width = 800, gridSize = 1, fullSize = true }) {
        super(...arguments);
        this.height = height;
        this.width = width;
        this.gridSize = gridSize;
        this.fullSize = fullSize;
    }

    @action setupGraph(el) {
        this.sizeGrid(el);
        this.createGraph(el);
    }

    createGraph(el) {
        const namespace = joint.shapes;
        const graph = new joint.dia.Graph({}, { cellNamespace: namespace });
        const paper = new joint.dia.Paper({
            el,
            model: graph,
            width: this.width,
            height: this.height,
            gridSize: this.gridSize,
            cellViewNamespace: namespace,
            interactive: false,
        });

        this.graph = graph;
        this.paper = paper;
        this.createPanningHandler(paper);

        if (typeof this.args.onSetup === 'function') {
            this.args.onSetup({ paper, graph, el }, this);
        }
    }

    sizeGrid(el) {
        if (this.fullSize) {
            const parentEl = el.parentElement;
            this.width = parentEl.offsetWidth;
            this.height = parentEl.offsetHeight;
        }
    }

    createPanningHandler(paper) {
        paper.on('blank:pointerdown', (event, x, y) => {
            this.dragStartPosition = { x, y };
        });

        paper.on('cell:pointerup blank:pointerup', () => {
            this.dragStartPosition = undefined;
        });

        paper.el.addEventListener('mousemove', (event) => {
            if (this.dragStartPosition) {
                paper.translate(event.offsetX - this.dragStartPosition.x, event.offsetY - this.dragStartPosition.y);
            }
        });
    }
}
