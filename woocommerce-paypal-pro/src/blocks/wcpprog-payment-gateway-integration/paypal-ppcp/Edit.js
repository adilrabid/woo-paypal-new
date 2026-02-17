import { decodeEntities } from "@wordpress/html-entities";
import { getPayPalPPCPSettings } from "../Utils";

export default () => {
    const description = decodeEntities(getPayPalPPCPSettings('description', ''));

    return (
        <>
            {description}
        </>
    );
}