export default function hasValidCoordinates(place) {
    const latitude = Number(place?.latitude);
    const longitude = Number(place?.longitude);

    return Number.isFinite(latitude) && Number.isFinite(longitude);
}
