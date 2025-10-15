export function initialize() {
    let waitForLeaflet = setInterval(() => {
        let leafletLoaded = window.L !== undefined;
        if (leafletLoaded) {
            clearInterval(waitForLeaflet);

            window.L.Bounds.prototype.intersects = function (bounds) {
                var min = this.min,
                    max = this.max,
                    min2 = bounds.min,
                    max2 = bounds.max,
                    xIntersects = max2.x >= min.x && min2.x <= max.x,
                    yIntersects = max2.y >= min.y && min2.y <= max.y;

                return xIntersects && yIntersects;
            };
        }
    }, 100);
}

export default {
    initialize,
};
